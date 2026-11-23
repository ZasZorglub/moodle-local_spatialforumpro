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
 * Site-bound entitlement verification.
 *
 * @package    local_spatialforumpro
 * @copyright  2026 Simple Solutions
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_spatialforumpro\local;

/**
 * Verifies offline licences. Only a signed claim can enable Pro.
 */
final class licence {
    /**
     * Derives a stable ID from the site's configured base URL.
     *
     * @param string $wwwroot Moodle's configured base URL
     * @return string
     */
    public static function site_id(string $wwwroot): string {
        return hash('sha256', rtrim($wwwroot, '/'));
    }

    /**
     * Calculates three UTC calendar months, clamping to the destination month's last day.
     *
     * @param int $start UTC epoch seconds
     * @return int
     */
    public static function trial_end(int $start): int {
        $date = (new \DateTimeImmutable('@' . $start))->setTimezone(new \DateTimeZone('UTC'));
        $month = $date->modify('first day of this month')->modify('+3 months');
        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'),
            min((int) $date->format('j'), (int) $month->format('t')))->getTimestamp();
    }

    /**
     * Loads the key from site configuration for development or the packaged provider key.
     *
     * @return string
     */
    public static function public_key(): string {
        global $CFG;
        $path = __DIR__ . '/../../public-key.pem';
        if (is_readable($path)) {
            return trim((string) file_get_contents($path));
        }
        return trim((string) ($CFG->local_spatialforumpro_publickey ?? ''));
    }

    /**
     * Reads the current site's signed licence claims.
     *
     * @return array|null
     */
    public static function current_claims(): ?array {
        global $CFG;
        return self::claims(
            (string) get_config('local_spatialforumpro', 'licence'),
            self::site_id($CFG->wwwroot),
            self::public_key(),
        );
    }

    /**
     * Validates a licence and returns its signed claims, including expired claims.
     *
     * @param string $token Base64 message and signature separated by a period
     * @param string $site Site fingerprint
     * @param string $key Public verification key
     * @return array|null
     */
    public static function claims(string $token, string $site, string $key): ?array {
        if ($key === '' || strlen($token) > 4096 || !extension_loaded('openssl')) {
            return null;
        }
        $parts = explode('.', trim($token));
        if (count($parts) !== 2) {
            return null;
        }
        $payload = base64_decode($parts[0], true);
        $signature = base64_decode($parts[1], true);
        $publickey = openssl_pkey_get_public($key);
        if ($payload === false || $signature === false || $publickey === false
                || openssl_verify($payload, $signature, $publickey, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }
        $claims = json_decode($payload, true, 8);
        if (!is_array($claims) || ($claims['version'] ?? null) !== 1
                || ($claims['product'] ?? null) !== 'local_spatialforumpro'
                || ($claims['site'] ?? null) !== $site
                || !is_string($claims['plan'] ?? null)
                || !array_key_exists($claims['plan'], plans::CATALOGUE)
                || !is_int($claims['start'] ?? null) || $claims['start'] < 0
                || !is_int($claims['end'] ?? null) || $claims['end'] <= $claims['start']
                || !in_array($claims['type'] ?? null, ['trial', 'paid'], true)) {
            return null;
        }
        if ($claims['type'] === 'trial' && $claims['end'] !== self::trial_end($claims['start'])) {
            return null;
        }
        return $claims;
    }

    /**
     * Returns the current access state.
     *
     * @param array|null $claims Verified claims
     * @param int $now UTC epoch seconds
     * @param int $users Number of site accounts
     * @return string
     */
    public static function state(?array $claims, int $now, int $users): string {
        if ($claims === null) {
            return 'inactive';
        }
        if ($now < $claims['start']) {
            return 'pending';
        }
        if ($now >= $claims['end']) {
            return 'expired';
        }
        $limit = plans::CATALOGUE[$claims['plan']]['limit'];
        if ($limit !== null && $users > $limit) {
            return 'overlimit';
        }
        return $claims['type'];
    }
}
