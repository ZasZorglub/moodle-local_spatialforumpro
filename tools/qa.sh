#!/usr/bin/env bash
# Run the regression suite and HTTP smoke checks using only disposable fixtures.
set -euo pipefail

if [[ $# != 2 ]]; then
    echo "Usage: bash tools/qa.sh MOODLE_5_2_GIT_CHECKOUT SPATIAL_FORUM_CHECKOUT" >&2
    exit 2
fi
moodle_source="$(cd "$1" && pwd)"
base_source="$(cd "$2" && pwd)"
pro_source="$(cd "$(dirname "$0")/.." && pwd)"
test -f "$moodle_source/vendor/bin/phpunit"
test -f "$base_source/version.php"
qa_id="sfpro-qa-$(date +%s)-$$"
docker network create --internal "$qa_id" >/dev/null || exit 1
trap 'docker stop "$qa_id-db" >/dev/null 2>&1; docker network rm "$qa_id" >/dev/null 2>&1' EXIT
docker run --rm -d --name "$qa_id-db" --network "$qa_id" --network-alias db --tmpfs /var/lib/postgresql/data -e POSTGRES_USER=sfpro -e POSTGRES_PASSWORD=qa-disposable-password postgres:16 >/dev/null || exit 1
for attempt in {1..20}; do
  docker exec "$qa_id-db" pg_isready -h 127.0.0.1 -U sfpro -d sfpro >/dev/null 2>&1 && break
  sleep 1
done
docker run --rm --name "$qa_id-php" --network "$qa_id" --entrypoint bash --mount "type=bind,source=$moodle_source,target=/moodle,readonly" --mount "type=bind,source=$base_source,target=/base,readonly" --mount "type=bind,source=$pro_source,target=/pro,readonly" moodlehq/moodle-php-apache:8.3 -euo pipefail -c '
mkdir -p /tmp/sfpro-moodle /tmp/sfpro-data /tmp/sfpro-phpunit
git -c safe.directory=/moodle -C /moodle archive HEAD | tar -C /tmp/sfpro-moodle -xf -
cp -a /moodle/vendor /tmp/sfpro-moodle/vendor
cd /tmp/sfpro-moodle
cp /pro/tools/phpunit-config.php config.php
mkdir -p public/mod/spatialforum public/local/spatialforumpro
tar --exclude=./.git -C /base -cf - . | tar -C public/mod/spatialforum -xf -
tar --exclude=./.git -C /pro -cf - . | tar -C public/local/spatialforumpro -xf -
php public/admin/tool/phpunit/cli/init.php --disable-composer >/tmp/sfpro-init.log 2>&1 || { tail -100 /tmp/sfpro-init.log; exit 1; }
tail -4 /tmp/sfpro-init.log
vendor/bin/phpunit --testsuite local_spatialforumpro_testsuite
umask 077
mkdir /tmp/sfpro-trials
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out /tmp/sfpro-key.pem 2>/dev/null
issuer_site=$(printf "%064d" 0)
issuer_script=public/local/spatialforumpro/tools/issue_licence.php
php "$issuer_script" /tmp/sfpro-key.pem /tmp/sfpro-trials "$issuer_site" starter trial 2026-11-23T00:00:00Z >/tmp/sfpro-first-token
if php "$issuer_script" /tmp/sfpro-key.pem /tmp/sfpro-trials "$issuer_site" starter trial 2026-11-23T00:00:00Z >/dev/null 2>/tmp/sfpro-second-error; then
  exit 1
fi
grep -F "A trial may already exist for this site" /tmp/sfpro-second-error
php "$issuer_script" /tmp/sfpro-key.pem /tmp/sfpro-trials "$issuer_site" starter paid 2027-02-23T00:00:00Z 2027-03-23T00:00:00Z >/dev/null
echo "Manual issuer: trial, duplicate denial, and paid issuance verified."


chmod -R 0777 /tmp/sfpro-data
php admin/cli/install_database.php --agree-license --fullname="Spatial forum Pro QA" --shortname=ProQA --adminuser=admin --adminpass=Qa-Disposable-01! --adminemail=qa@example.invalid >/tmp/sfpro-web-install.log 2>&1 || { tail -32 /tmp/sfpro-web-install.log; exit 1; }
cmid=$(php public/local/spatialforumpro/tools/http-fixture.php)
php -S 127.0.0.1:8081 -t public >/tmp/sfpro-web.log 2>&1 &
webpid=$!
trap "kill $webpid" EXIT
login() {
    local user="$1" password="$2" jar="$3"
    curl -fsS -c "$jar" http://127.0.0.1:8081/login/index.php >/tmp/sfpro-login
    local token
    token=$(sed -n "s/.*name=\"logintoken\" value=\"\([^\"]*\)\".*/\1/p" /tmp/sfpro-login | head -1)
    test -n "$token"
    curl -fsS -L -b "$jar" -c "$jar" --data-urlencode "username=$user" --data-urlencode "password=$password" \
        --data-urlencode "logintoken=$token" http://127.0.0.1:8081/login/index.php >/tmp/sfpro-login-result
}
sleep 1
login admin Qa-Disposable-01! /tmp/sfpro-admin-cookie
qa_url=http://127.0.0.1:8081/local/spatialforumpro/index.php
curl -fsS -b /tmp/sfpro-admin-cookie "$qa_url" >/tmp/sfpro-inactive || { tail -30 /tmp/sfpro-web.log; exit 1; }
grep -Foq "No valid Spatial forum Pro licence is installed." /tmp/sfpro-inactive
echo "Inactive report verified."
openssl pkey -in /tmp/sfpro-key.pem -pubout -out /tmp/sfpro-http-public.pem
site_fingerprint=$(printf "%s" http://127.0.0.1:8081 | sha256sum | cut -d" " -f1)
php "$issuer_script" /tmp/sfpro-key.pem /tmp/sfpro-trials "$site_fingerprint" starter trial "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >/tmp/sfpro-http-token
php admin/cli/cfg.php --component=local_spatialforumpro --name=licence --set="$(cat /tmp/sfpro-http-token)" >/dev/null
php admin/cli/scheduled_task.php --execute=\\local_spatialforumpro\\task\\weekly_report
curl -fsS -b /tmp/sfpro-admin-cookie "$qa_url?forumfilter=$cmid" >/tmp/sfpro-active || { tail -30 /tmp/sfpro-web.log; exit 1; }
grep -Foq "Design discussion" /tmp/sfpro-active
grep -Foq "threads unanswered at the end of the week" /tmp/sfpro-active
echo "Active report and weekly summary verified."
curl -fsS -b /tmp/sfpro-admin-cookie "$qa_url?view=moderation" >/tmp/sfpro-moderation
grep -Foq "QA unanswered thread" /tmp/sfpro-moderation
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
'
