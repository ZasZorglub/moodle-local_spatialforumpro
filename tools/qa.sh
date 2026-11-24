#!/usr/bin/env bash
# Run the regression suite and HTTP smoke checks using only disposable fixtures.
set -euo pipefail

if [[ $# -lt 2 || $# -gt 3 ]]; then
    echo "Usage: bash tools/qa.sh MOODLE_GIT_CHECKOUT SPATIAL_FORUM_CHECKOUT [SCREENSHOT_DIRECTORY]" >&2
    exit 2
fi
moodle_source="$(cd "$1" && pwd)"
base_source="$(cd "$2" && pwd)"
pro_source="$(cd "$(dirname "$0")/.." && pwd)"
test -f "$moodle_source/vendor/bin/phpunit"
test -f "$moodle_source/composer.phar"
test -f "$base_source/version.php"
qa_id="sfpro-qa-$(date +%s)-$$"
browser_args=()
qa_url=http://127.0.0.1:8081
docker network create --internal "$qa_id" >/dev/null || exit 1
cleanup() {
    if [[ $# -eq 1 ]]; then
        docker stop "$qa_id-browser" >/dev/null 2>&1 || true
    fi
    docker stop "$qa_id-db" >/dev/null 2>&1 || true
    docker network rm "$qa_id" >/dev/null 2>&1 || true
}
if [[ $# -eq 3 ]]; then
    screenshots="$(cd "$3" && pwd)"
    qa_url=http://moodle:8081
    browser_args+=(--env SFPRO_BROWSER=1 --mount "type=bind,source=$screenshots,target=/screenshots")
    trap 'cleanup browser' EXIT
    docker run --rm -d --name "$qa_id-browser" --network "$qa_id" --network-alias browser \
        --shm-size=512m selenium/standalone-firefox:4 >/dev/null
else
    trap cleanup EXIT
fi
docker run --rm -d --name "$qa_id-db" --network "$qa_id" --network-alias db --tmpfs /var/lib/postgresql/data -e POSTGRES_USER=sfpro -e POSTGRES_PASSWORD=qa-disposable-password postgres:17-alpine >/dev/null
for attempt in {1..20}; do
  docker exec "$qa_id-db" pg_isready -h 127.0.0.1 -U sfpro -d sfpro >/dev/null 2>&1 && break
  sleep 1
done
docker run --rm --name "$qa_id-php" --network "$qa_id" --network-alias moodle --env "SFPRO_WWWROOT=$qa_url" \
    ${browser_args[@]+"${browser_args[@]}"} --entrypoint bash --mount "type=bind,source=$moodle_source,target=/moodle,readonly" \
    --mount "type=bind,source=$base_source,target=/base,readonly" --mount "type=bind,source=$pro_source,target=/pro,readonly" \
    moodlehq/moodle-php-apache:8.3 -Eeuo pipefail -c '
mkdir -p /tmp/sfpro-moodle /tmp/sfpro-data /tmp/sfpro-phpunit
git -c safe.directory=/moodle -C /moodle archive HEAD | tar -C /tmp/sfpro-moodle -xf -
cp -a /moodle/vendor /tmp/sfpro-moodle/vendor
cp /moodle/composer.phar /tmp/sfpro-moodle/composer.phar
cd /tmp/sfpro-moodle
cp /pro/tools/phpunit-config.php config.php
webroot=.
[[ ! -f public/version.php ]] || webroot=public
mkdir -p "$webroot/mod/spatialforum" "$webroot/local/spatialforumpro"
tar --exclude=./.git -C /base -cf - . | tar -C "$webroot/mod/spatialforum" -xf -
tar --exclude=./.git -C /pro -cf - . | tar -C "$webroot/local/spatialforumpro" -xf -
php "$webroot/admin/tool/phpunit/cli/init.php" --disable-composer >/tmp/sfpro-init.log 2>&1 || { tail -100 /tmp/sfpro-init.log; exit 1; }
tail -4 /tmp/sfpro-init.log
vendor/bin/phpunit --testsuite local_spatialforumpro_testsuite
umask 077
mkdir /tmp/sfpro-trials
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out /tmp/sfpro-key.pem 2>/dev/null
issuer_script="$webroot/local/spatialforumpro/tools/issue_licence.php"
bash "$webroot/local/spatialforumpro/tools/qa_issuer.sh" /tmp/sfpro-key.pem /tmp/sfpro-trials


chmod -R 0777 /tmp/sfpro-data
php admin/cli/install_database.php --agree-license --fullname="Spatial forum Pro Demo" --shortname="Demo Campus" --adminuser=admin --adminpass=Qa-Disposable-01! --adminemail=qa@example.invalid >/tmp/sfpro-web-install.log 2>&1 || { tail -32 /tmp/sfpro-web-install.log; exit 1; }
cmid=$(php "$webroot/local/spatialforumpro/tools/http-fixture.php") || { printf "%s\n" "$cmid" >&2; exit 1; }
[[ "$cmid" =~ ^[0-9]+$ ]] || { echo "Synthetic forum setup did not return a course module ID"; exit 1; }
php -S 0.0.0.0:8081 -t "$webroot" >/tmp/sfpro-web.log 2>&1 &
webpid=$!
trap "kill $webpid" EXIT
diagnose() {
    echo "HTTP check failed at runner line $1" >&2
    tail -15 /tmp/sfpro-web.log >&2
    for page in /tmp/sfpro-login /tmp/sfpro-login-result /tmp/sfpro-inactive /tmp/sfpro-active; do
        if [[ -s "$page" ]]; then
            printf "%s " "$page" >&2
            grep -oE "<title>[^<]*</title>" "$page" >&2 || true
        fi
    done
}
trap "diagnose \$LINENO" ERR
login() {
    local user="$1" password="$2" jar="$3"
    curl -fsS -c "$jar" "$SFPRO_WWWROOT/login/index.php" >/tmp/sfpro-login
    local token
    token=$(sed -n "s/.*name=\"logintoken\" value=\"\([^\"]*\)\".*/\1/p" /tmp/sfpro-login | head -1)
    test -n "$token"
    curl -fsS -L -b "$jar" -c "$jar" --data-urlencode "username=$user" --data-urlencode "password=$password" \
        --data-urlencode "logintoken=$token" "$SFPRO_WWWROOT/login/index.php" >/tmp/sfpro-login-result
}
sleep 1
echo "Synthetic course and HTTP server prepared."
login admin Qa-Disposable-01! /tmp/sfpro-admin-cookie
qa_url="$SFPRO_WWWROOT/local/spatialforumpro/index.php"
curl -fsS -b /tmp/sfpro-admin-cookie "$qa_url" >/tmp/sfpro-inactive || { tail -30 /tmp/sfpro-web.log; exit 1; }
grep -Foq "No valid Spatial forum Pro licence is installed." /tmp/sfpro-inactive
echo "Inactive report verified."
openssl pkey -in /tmp/sfpro-key.pem -pubout -out /tmp/sfpro-http-public.pem
site_fingerprint=$(printf "%s" "$SFPRO_WWWROOT" | sha256sum | cut -d" " -f1)
php "$issuer_script" /tmp/sfpro-key.pem /tmp/sfpro-trials "$site_fingerprint" starter trial "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >/tmp/sfpro-http-token
php admin/cli/cfg.php --component=local_spatialforumpro --name=licence --set="$(cat /tmp/sfpro-http-token)" >/dev/null
php admin/cli/scheduled_task.php --execute=\\local_spatialforumpro\\task\\weekly_report
curl -fsS -b /tmp/sfpro-admin-cookie "$qa_url?forumfilter=$cmid" >/tmp/sfpro-active || { tail -30 /tmp/sfpro-web.log; exit 1; }
grep -Foq "Design discussion" /tmp/sfpro-active
grep -Foq "threads unanswered at the end of the week" /tmp/sfpro-active
echo "Active report and weekly summary verified."
curl -fsS -b /tmp/sfpro-admin-cookie "$qa_url?view=moderation" >/tmp/sfpro-moderation
if [[ ${SFPRO_BROWSER:-} == 1 ]]; then
    grep -Foq "Which prototype should we test first?" /tmp/sfpro-moderation
else
    grep -Foq "QA unanswered thread" /tmp/sfpro-moderation
fi
echo "Moderation verified."
sesskey=$(sed -n "s/.*name=\"sesskey\" value=\"\([^\"]*\)\".*/\1/p" /tmp/sfpro-active | head -1)
test -n "$sesskey"
curl -fsS -b /tmp/sfpro-admin-cookie -d "sesskey=$sesskey" "$qa_url?download=1&forumfilter=$cmid" >/tmp/sfpro-csv
grep -Foq "Design discussion" /tmp/sfpro-csv
echo "CSV verified."
login qa-learner Qa-Disposable-04! /tmp/sfpro-learner-cookie
status=$(curl -sS -b /tmp/sfpro-learner-cookie -w "%{http_code}" -o /tmp/sfpro-denied "$qa_url")
test "$status" -ge 400 || { echo "Unexpected learner report status: $status"; grep -Foq "Sorry, but you do not currently have permissions" /tmp/sfpro-denied; }
echo "HTTP: inactive view, active report, scheduled summary, moderation, CSV and learner denial verified."
if [[ ${SFPRO_BROWSER:-} == 1 ]]; then
    php "$webroot/local/spatialforumpro/tools/capture.php"
fi
'
