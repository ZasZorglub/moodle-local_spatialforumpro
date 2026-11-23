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
 * Report queries and access checks.
 *
 * @package    local_spatialforumpro
 * @copyright  2026 Simple Solutions
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_spatialforumpro\local;

use mod_spatialforum\local\group_access;

/**
 * Computes aggregate participation without retaining personal data.
 */
final class report {
    /**
     * Returns currently accessible forums and their participation totals.
     *
     * @param int|null $since Include participation at or after this UTC timestamp
     * @param int|null $until Exclude posts at or after this UTC timestamp
     * @param int $filtercourse Limit to this course
     * @param int $filterforum Limit to this course module
     * @param bool $moderation Include recent and unanswered thread details
     * @return \Generator
     */
    public static function rows(
        ?int $since,
        ?int $until = null,
        int $filtercourse = 0,
        int $filterforum = 0,
        bool $moderation = false,
    ): \Generator {
        global $DB;
        $until ??= time() + 1;
        $previousstart = $since === null ? null : max(0, $since - ($until - $since));
        foreach (self::forums($filtercourse, $filterforum) as [$course, $cm, $activity]) {
            $courseid = (int) $course->id;
            $coursecontext = \context_course::instance($courseid);
            $context = $cm->context;
            $access = new group_access($activity, $cm->get_course_module_record());
            $distinct = [];
            $topics = 0;
            $replies = 0;
            $latest = 0;
            $previous = 0;
            $threads = [];
            $roots = [];

            $params = ['forum' => $activity->id, 'since' => $moderation ? 0 : ($previousstart ?? 0), 'until' => $until];
            $posts = $DB->get_recordset_select(
                'spatialforum_posts',
                'spatialforumid = :forum AND deleted = 0 AND timecreated >= :since AND timecreated < :until',
                $params,
                'id ASC',
                'id, spatialforumid, groupid, userid, replytoid, timecreated' . ($moderation ? ', subject, trackid' : ''),
            );
            try {
                foreach ($posts as $post) {
                    if (!$access->can_view($post)) {
                        continue;
                    }
                    $parent = (int) $post->replytoid;
                    $created = (int) $post->timecreated;
                    if ($moderation) {
                        $id = (int) $post->id;
                        if (!$parent) {
                            $roots[$id] = $id;
                            $threads[$id] = [
                                'subject' => $post->subject,
                                'trackid' => (int) $post->trackid,
                                'latest' => $created,
                                'replies' => 0,
                            ];
                        } else if (isset($roots[$parent])) {
                            $root = $roots[$parent];
                            $roots[$id] = $root;
                            $threads[$root]['latest'] = max($threads[$root]['latest'], $created);
                            $threads[$root]['replies']++;
                        }
                    }
                    if ($since !== null && $created < $since) {
                        if ($created >= $previousstart) {
                            $previous++;
                        }
                        continue;
                    }
                    $distinct[(int) $post->userid] = true;
                    if ($parent > 0) {
                        $replies++;
                    } else {
                        $topics++;
                    }
                    $latest = max($latest, $created);
                }
            } finally {
                $posts->close();
            }
            unset($distinct[0]);
            $unanswered = array_filter($threads, static fn(array $thread): bool => $thread['replies'] === 0);
            uasort($threads, static fn(array $a, array $b): int => $b['latest'] <=> $a['latest']);
            uasort($unanswered, static fn(array $a, array $b): int => $b['latest'] <=> $a['latest']);
            yield [
                'course' => format_string($course->fullname, true, ['context' => $coursecontext]),
                'forum' => format_string($activity->name, true, ['context' => $context]),
                'cmid' => (int) $cm->id,
                'courseid' => (int) $courseid,
                'contributors' => count($distinct),
                'topics' => $topics,
                'replies' => $replies,
                'latest' => $latest,
                'previous' => $since === null ? null : $previous,
                'change' => $since === null ? null : $topics + $replies - $previous,
                'unanswered' => count($unanswered),
                'unansweredthreads' => array_slice($unanswered, 0, 5, true),
                'recentthreads' => array_slice($threads, 0, 5, true),
            ];
        }
    }

    /**
     * Lists forums that the viewer may access.
     *
     * @param int $filtercourse Optional course ID
     * @param int $filterforum Optional course module ID
     * @return \Generator
     */
    public static function forums(int $filtercourse = 0, int $filterforum = 0): \Generator {
        global $DB, $USER;
        require_capability('local/spatialforumpro:viewreport', \context_system::instance());
        $courseids = $filtercourse ? [$filtercourse] :
            $DB->get_fieldset_select('spatialforum', 'DISTINCT course', 'importstate = 0');
        sort($courseids, SORT_NUMERIC);
        foreach ($courseids as $courseid) {
            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course || !can_access_course($course, $USER, '', true)) {
                continue;
            }
            foreach (get_fast_modinfo($course)->get_instances_of('spatialforum') as $cm) {
                if (($filterforum && (int) $cm->id !== $filterforum) || !$cm->uservisible
                        || !has_capability('mod/spatialforum:view', $cm->context)) {
                    continue;
                }
                $activity = $DB->get_record('spatialforum', ['id' => $cm->instance, 'importstate' => 0]);
                if ($activity) {
                    yield [$course, $cm, $activity];
                }
            }
        }
    }

    /**
     * Flattens formatted text and prevents formula execution in spreadsheet apps.
     *
     * @param string $value Value that may include Moodle formatting or user-entered text
     * @return string
     */
    public static function csv_cell(string $value): string {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_match('/^[\s\p{Z}\x{FEFF}]*[=+@-]|^[\t\r\n]/u', $value) === 1 ? "'" . $value : $value;
    }
}
