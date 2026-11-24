<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Command-line issuer for Spatial forum Pro licences.
 *
 * @package    local_spatialforumpro
 * @copyright  2026 Simple Solutions
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once(__DIR__ . '/../classes/local/plans.php');
require_once(__DIR__ . '/../classes/local/licence.php');

use local_spatialforumpro\local\licence;
use local_spatialforumpro\local\plans;

/**
 * Parse an ISO instant in UTC.
 *
 * @param string $input Input date
 * @return int
 */
function spatialforumpro_issuer_time(string $input): int {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $input, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $input || $date->getTimestamp() < 0) {
        throw new InvalidArgumentException('Use a valid UTC date in YYYY-MM-DDTHH:MM:SSZ format.');
    }
    return $date->getTimestamp();
}

/**
 * Read a Marketplace order transcribed by the issuing operator.
 *
 * @param string $path Private order file
 * @return array
 */
function spatialforumpro_issuer_order(string $path): array {
    if (!is_file($path) || !is_readable($path) || (fileperms($path) & 0077) || filesize($path) > 4096) {
        throw new RuntimeException('Use a private JSON order file with owner-only permissions.');
    }
    $order = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    $reference = $order['reference'] ?? null;
    $plan = $order['plan'] ?? null;
    $interval = $order['interval'] ?? null;
    if (!is_string($reference) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $reference)
            || !is_string($plan) || !is_string($interval) || !in_array($interval, ['monthly', 'yearly'], true)
            || ($order['status'] ?? null) !== 'paid' || ($order['currency'] ?? null) !== 'USD') {
        throw new InvalidArgumentException('Use a verified paid Marketplace order with a stable reference and USD pricing.');
    }
    $price = plans::CATALOGUE[$plan][$interval] ?? null;
    if ($price === null || ($order['subtotal_cents'] ?? null) !== $price * 100) {
        throw new InvalidArgumentException('The order subtotal must match the published price before tax.');
    }
    foreach (['site', 'start', 'end'] as $field) {
        if (!is_string($order[$field] ?? null)) {
            throw new InvalidArgumentException('Order site, start and end are required.');
        }
    }
    return $order;
}

/**
 * Publish a complete record without overwriting an existing entry.
 *
 * @param string $path Final record path
 * @param array $record Claims, token and any order details
 * @return bool Whether this call published the record
 */
function spatialforumpro_issuer_record(string $path, array $record): bool {
    $tmp = tempnam(dirname($path), '.issue-');
    if ($tmp === false) {
        throw new RuntimeException('Could not create a temporary registry record.');
    }
    try {
        $json = json_encode($record, JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($tmp, $json) !== strlen($json)) {
            throw new RuntimeException('Could not write the complete registry record.');
        }
        if (@link($tmp, $path)) {
            return true;
        }
        clearstatcache(true, $path);
        if (!is_file($path)) {
            throw new RuntimeException('Could not publish the registry record.');
        }
        return false;
    } finally {
        unlink($tmp);
    }
}

try {
    umask(0077);
    $usage = "Usage:\n  php tools/issue_licence.php PRIVATE_PEM PRIVATE_REGISTRY SITE_FINGERPRINT PLAN trial START_UTC"
        . "\n  php tools/issue_licence.php PRIVATE_PEM PRIVATE_REGISTRY --marketplace-order PRIVATE_JSON";
    $paid = ($argv[3] ?? null) === '--marketplace-order';
    if (($paid && $argc !== 5) || (!$paid && ($argc !== 7 || $argv[5] !== 'trial'))) {
        throw new InvalidArgumentException($usage);
    }
    [, $keypath, $registry] = $argv;
    if (!is_dir($registry) || !is_file($keypath) || !is_readable($keypath)
            || (fileperms($registry) & 0077) || (fileperms($keypath) & 0077)) {
        throw new RuntimeException('Use an existing private registry directory and private PEM, both with owner-only permissions.');
    }
    $order = $paid ? spatialforumpro_issuer_order($argv[4]) : null;
    $site = $paid ? $order['site'] : $argv[3];
    $plan = $paid ? $order['plan'] : $argv[4];
    if (!preg_match('/^[a-f0-9]{64}$/D', $site) || !isset(plans::CATALOGUE[$plan])) {
        throw new InvalidArgumentException('Invalid site fingerprint or plan.');
    }
    $start = spatialforumpro_issuer_time($paid ? $order['start'] : $argv[6]);
    $end = $paid ? spatialforumpro_issuer_time($order['end']) : licence::trial_end($start);
    if ($end <= $start) {
        throw new InvalidArgumentException('The end must follow the start.');
    }
    if ($paid && $end - $start > ($order['interval'] === 'monthly' ? 32 : 367) * 86400) {
        throw new InvalidArgumentException('The licence term exceeds the purchased billing interval.');
    }
    $trialpath = $registry . '/' . $site . '.trial.json';
    if ($paid && is_file($trialpath)) {
        $trial = json_decode(file_get_contents($trialpath), true, 8, JSON_THROW_ON_ERROR);
        if (!is_int($trial['end'] ?? null) || $start < $trial['end']) {
            throw new InvalidArgumentException('The paid period must start after the free trial ends.');
        }
    }
    $claims = [
        'version' => 1,
        'product' => 'local_spatialforumpro',
        'site' => $site,
        'plan' => $plan,
        'type' => $paid ? 'paid' : 'trial',
        'start' => $start,
        'end' => $end,
    ];
    $payload = json_encode($claims, JSON_THROW_ON_ERROR);
    $key = openssl_pkey_get_private(file_get_contents($keypath), (string) getenv('SFPRO_KEY_PASSPHRASE'));
    $details = $key ? openssl_pkey_get_details($key) : false;
    if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048
            || !openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Unable to sign. Use an RSA private key of at least 2048 bits and its passphrase.');
    }
    $token = base64_encode($payload) . '.' . base64_encode($signature);
    $record = $claims + ['licence' => $token];
    $path = $trialpath;
    if ($paid) {
        $path = $registry . '/marketplace-' . hash('sha256', $order['reference']) . '.json';
        $record += [
            'reference' => $order['reference'],
            'interval' => $order['interval'],
            'subtotal_cents' => $order['subtotal_cents'],
            'currency' => $order['currency'],
        ];
    }
    if (!spatialforumpro_issuer_record($path, $record)) {
        if (!$paid) {
            throw new RuntimeException('A trial may already exist for this site.');
        }
        $existing = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if ($existing !== $record) {
            throw new RuntimeException('This order has already been issued with different details or a different signing key.');
        }
    }
    fwrite(STDOUT, $token . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
