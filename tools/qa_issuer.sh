#!/usr/bin/env bash
# Exercise the manual fulfilment workflow with an isolated registry and a disposable key.
set -euo pipefail
umask 077

issuer="$(dirname "$0")/issue_licence.php"
key=$1
registry=$2
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
site=$(printf '%064d' 1)
other_site=$(printf '%064d' 2)
issue() {
    php "$issuer" "$key" "$registry" "$@"
}
reject() {
    local expected=$1
    shift
    if issue "$@" >"$work/unexpected-licence" 2>"$work/error"; then
        echo "Issuer accepted an invalid request." >&2
        exit 1
    fi
    test ! -s "$work/unexpected-licence"
    grep -Fq "$expected" "$work/error"
}
order() {
    local reference=$1 fingerprint=$2 status=$3 cents=$4 start=$5 end=$6
    local plan=${7:-starter} interval=${8:-monthly}
    cat >"$work/order.json" <<JSON
{"reference":"$reference","site":"$fingerprint","status":"$status","currency":"USD","subtotal_cents":$cents,"plan":"$plan","interval":"$interval","start":"$start","end":"$end"}
JSON
}

issue "$site" starter trial 2026-11-30T12:00:00Z >"$work/trial"
reject 'A trial may already exist for this site' "$site" growth trial 2027-01-01T12:00:00Z
order synthetic-001 "$site" pending 1000 2027-02-28T12:00:00Z 2027-03-28T12:00:00Z
reject 'verified paid Marketplace order' --marketplace-order "$work/order.json"
order synthetic-001 "$site" paid 999 2027-02-28T12:00:00Z 2027-03-28T12:00:00Z
reject 'subtotal must match' --marketplace-order "$work/order.json"
order synthetic-001 "$site" paid 1000 2027-02-27T12:00:00Z 2027-03-27T12:00:00Z
reject 'after the free trial ends' --marketplace-order "$work/order.json"
order synthetic-001 "$site" paid 1000 2027-02-28T12:00:00Z 2027-04-28T12:00:00Z
reject 'exceeds the purchased billing interval' --marketplace-order "$work/order.json"

order synthetic-001 "$site" paid 1000 2027-02-28T12:00:00Z 2027-03-28T12:00:00Z
issue --marketplace-order "$work/order.json" >"$work/first" &
issue_pid=$!
issue --marketplace-order "$work/order.json" >"$work/retry"
wait "$issue_pid"
cmp "$work/first" "$work/retry"

order synthetic-001 "$other_site" paid 1000 2027-02-28T12:00:00Z 2027-03-28T12:00:00Z
reject 'already been issued with different details' --marketplace-order "$work/order.json"

order synthetic-002 "$site" paid 1000 2027-03-28T12:00:00Z 2027-04-28T12:00:00Z
issue --marketplace-order "$work/order.json" >"$work/renewal"

for option in 'starter monthly 1000' 'starter yearly 10000' 'growth monthly 2500' 'growth yearly 25000' \
    'scale monthly 5000' 'scale yearly 50000'; do
    read -r plan interval cents <<<"$option"
    end=2027-04-30T12:00:00Z
    if [[ $interval == yearly ]]; then end=2028-03-30T12:00:00Z; fi
    order "synthetic-$plan-$interval" "$other_site" paid "$cents" 2027-03-30T12:00:00Z "$end" "$plan" "$interval"
    issue --marketplace-order "$work/order.json" >/dev/null
done

SFPRO_KEY_PASSPHRASE=${SFPRO_KEY_PASSPHRASE:-} openssl pkey -in "$key" -passin env:SFPRO_KEY_PASSPHRASE \
    -pubout -out "$work/public"
php -r '
require $argv[1] . "/classes/local/plans.php";
require $argv[1] . "/classes/local/licence.php";
$key = file_get_contents($argv[2] . "/public");
$site = $argv[3];
$other = $argv[4];
foreach (["trial" => ["trial", "2027-02-28"], "first" => ["paid", "2027-03-28"], "renewal" => ["paid", "2027-04-28"]]
    as $file => [$type, $date]) {
    $token = trim(file_get_contents($argv[2] . "/" . $file));
    $claims = local_spatialforumpro\local\licence::claims($token, $site, $key);
    $wrongsite = local_spatialforumpro\local\licence::claims($token, $other, $key);
    if (!$claims || $claims["type"] !== $type || gmdate("Y-m-d", $claims["end"]) !== $date || $wrongsite !== null) {
        exit(1);
    }
}
' "$(dirname "$0")/.." "$work" "$site" "$other_site"
echo "Verified trial, six prices, renewal, order retry, and rejected invalid orders and site mismatches."
