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
 * Canonical Spatial forum Pro plan catalogue.
 *
 * @package    local_spatialforumpro
 * @copyright  2026 Simple Solutions
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_spatialforumpro\local;

/**
 * Published pilot prices in whole USD and total user limits per site.
 */
final class plans {
    /** @var array Plan label key, inclusive limit, monthly and yearly price. */
    public const CATALOGUE = [
        'starter' => ['limit' => 2000, 'monthly' => 10, 'yearly' => 100],
        'growth' => ['limit' => 10000, 'monthly' => 25, 'yearly' => 250],
        'scale' => ['limit' => 25000, 'monthly' => 50, 'yearly' => 500],
        'enterprise' => ['limit' => null, 'monthly' => null, 'yearly' => null],
    ];

    /**
     * Counts non-deleted site accounts, including suspended accounts and excluding Guest.
     *
     * @return int
     */
    public static function count_users(): int {
        global $CFG, $DB;
        return $DB->count_records_select('user', 'deleted = 0 AND id <> :guest', ['guest' => $CFG->siteguest]);
    }
}
