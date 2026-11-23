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
 * Generates the latest completed UTC week summary.
 *
 * @package    local_spatialforumpro
 * @copyright  2026 Simple Solutions
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_spatialforumpro\task;

use local_spatialforumpro\local\licence;
use local_spatialforumpro\local\plans;
use local_spatialforumpro\local\report;

/**
 * Generates one site-wide snapshot per completed week while enabled.
 */
final class weekly_report extends \core\task\scheduled_task {
    /**
     * Returns the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('weeklytask', 'local_spatialforumpro');
    }

    /**
     * Generates and stores the latest weekly totals.
     */
    public function execute(): void {
        if (!get_config('local_spatialforumpro', 'weeklyenabled')) {
            return;
        }
        $now = time();
        $claims = licence::current_claims();
        $state = licence::state($claims, $now, plans::count_users());
        if (!is_siteadmin() || !in_array($state, ['trial', 'paid'], true)) {
            return;
        }
        [$start, $end] = self::previous_week($now);
        $snapshot = json_decode((string) get_config('local_spatialforumpro', 'weeklysnapshot'), true);
        if (($snapshot['end'] ?? 0) >= $end) {
            return;
        }
        $totals = ['forums' => 0, 'topics' => 0, 'replies' => 0, 'unanswered' => 0];
        foreach (report::rows($start, $end, moderation: true) as $row) {
            $totals['forums']++;
            foreach (['topics', 'replies', 'unanswered'] as $field) {
                $totals[$field] += $row[$field];
            }
        }
        $totals['start'] = $start;
        $totals['end'] = $end;
        set_config('weeklysnapshot', json_encode($totals, JSON_THROW_ON_ERROR), 'local_spatialforumpro');
    }

    /**
     * Returns the inclusive start and exclusive end of the previous UTC week.
     *
     * @param int $now Reference timestamp
     * @return array
     */
    public static function previous_week(int $now): array {
        $date = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('UTC'));
        $end = $date->modify('monday this week')->setTime(0, 0);
        return [$end->modify('-7 days')->getTimestamp(), $end->getTimestamp()];
    }
}
