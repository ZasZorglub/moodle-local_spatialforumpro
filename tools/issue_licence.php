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

try {
    if ($argc < 7 || $argc > 8) {
        throw new InvalidArgumentException(
            'Usage: php tools/issue_licence.php PRIVATE_PEM PRIVATE_LEDGER_DIR SITE_FINGERPRINT PLAN trial|paid START_UTC [END_UTC]'
        );
    }
    [, $keypath, $registry, $site, $plan, $type, $startutc] = $argv;
    if (!preg_match('/^[a-f0-9]{64}$/D', $site) || !isset(plans::CATALOGUE[$plan])
            || !in_array($type, ['trial', 'paid'], true) || ($type === 'trial' ? $argc !== 7 : $argc !== 8)) {
        throw new InvalidArgumentException('Invalid site fingerprint, plan, or term.');
    }
    if (!is_dir($registry) || !is_readable($keypath) || (fileperms($registry) & 0077) || (fileperms($keypath) & 0077)) {
        throw new RuntimeException('Use an existing private registry directory and private PEM, both with owner-only permissions.');
    }
    $start = spatialforumpro_issuer_time($startutc);
    $end = $type === 'trial' ? licence::trial_end($start) : spatialforumpro_issuer_time($argv[7]);
    if ($end <= $start) {
        throw new InvalidArgumentException('The end must follow the start.');
    }
    $claims = [
        'version' => 1,
        'product' => 'local_spatialforumpro',
        'site' => $site,
        'plan' => $plan,
        'type' => $type,
        'start' => $start,
        'end' => $end,
    ];
    $payload = json_encode($claims, JSON_THROW_ON_ERROR);
    $key = openssl_pkey_get_private(file_get_contents($keypath), getenv('SFPRO_KEY_PASSPHRASE') ?: '');
    if (!$key || !openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Unable to sign with the supplied private key.');
    }
    $token = base64_encode($payload) . '.' . base64_encode($signature);
    if ($type === 'trial') {
        $path = rtrim($registry, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $site . '.trial.json';
        $entry = fopen($path, 'xb');
        if (!$entry) {
            throw new RuntimeException('Unable to reserve the trial. A trial may already exist for this site.');
        }
        try {
            $record = json_encode($claims + ['licence' => $token], JSON_THROW_ON_ERROR) . PHP_EOL;
            if (fwrite($entry, $record) !== strlen($record) || !fflush($entry)) {
                throw new RuntimeException('Trial record could not be fully written. Check the registry before retrying.');
            }
        } finally {
            fclose($entry);
        }
    }
    fwrite(STDOUT, $token . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
