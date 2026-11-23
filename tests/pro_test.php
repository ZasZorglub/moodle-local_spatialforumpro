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
 * Regression tests for Spatial forum Pro.
 *
 * @package    local_spatialforumpro
 * @copyright  2026 Simple Solutions
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_spatialforumpro;

use local_spatialforumpro\local\licence;
use local_spatialforumpro\local\report;
use local_spatialforumpro\task\weekly_report;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exercises site licences, report visibility and scheduled aggregation.
 */
#[CoversClass(licence::class)]
#[CoversClass(report::class)]
#[CoversClass(weekly_report::class)]
final class pro_test extends \advanced_testcase {
    /**
     * Tests the complete signed trial, including UTC expiry and site binding.
     */
    public function test_site_licence(): void {
        [$token, $publickey, $start, $end] = $this->make_trial('https://demo.example.test');
        $this->assertSame(strtotime('2026-04-30T14:30:00Z'), $end);
        $site = licence::site_id('https://demo.example.test/');
        $claims = licence::claims($token, $site, $publickey);
        $this->assertNotNull($claims);
        $this->assertSame('pending', licence::state($claims, $start - 1, 2000));
        $this->assertSame('trial', licence::state($claims, $start, 2000));
        $this->assertSame('overlimit', licence::state($claims, $start, 2001));
        $this->assertSame('expired', licence::state($claims, $end, 1));
        $this->assertNull(licence::claims($token, licence::site_id('https://other.example.test'), $publickey));
        $this->assertNull(licence::claims('X' . $token, $site, $publickey));
    }

    /**
     * Tests that forum filters, count windows, deleted posts and thread links agree.
     */
    public function test_report_and_weekly_snapshot(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $other = $generator->create_course();
        $forum = $generator->create_module('spatialforum', ['course' => $course->id]);
        $second = $generator->create_module('spatialforum', ['course' => $other->id]);
        $author = $generator->create_user();
        [$start, $end] = weekly_report::previous_week(time());
        $root = $this->add_post($forum, $author, 0, $start - DAYSECS);
        $this->add_post($forum, $author, $root, $start + 2);
        $waiting = $this->add_post($forum, $author, 0, $start + 3);
        $this->add_post($forum, $author, $waiting, $start + 4, true);
        $this->add_post($second, $author, 0, $start + 4);
        $report = iterator_to_array(report::rows($start, $end, (int) $course->id, (int) $forum->cmid, true));
        $this->assertCount(1, $report);
        $this->assertSame(1, $report[0]['contributors']);
        $this->assertSame(1, $report[0]['topics']);
        $this->assertSame(1, $report[0]['replies']);
        $this->assertSame(1, $report[0]['previous']);
        $this->assertSame(1, $report[0]['change']);
        $this->assertSame(1, $report[0]['unanswered']);
        $this->assertArrayHasKey($waiting, $report[0]['unansweredthreads']);
        $this->assertArrayNotHasKey($root, $report[0]['unansweredthreads']);
        $this->assertSame("'=1+1", report::csv_cell('=1+1'));
        $this->assertSame("'\xC2\xA0+1", report::csv_cell('&nbsp;+1'));

        [$token, $publickey] = $this->make_trial($CFG->wwwroot, time() - DAYSECS);
        $CFG->local_spatialforumpro_publickey = $publickey;
        set_config('licence', $token, 'local_spatialforumpro');
        $task = new weekly_report();
        $task->execute();
        $this->assertFalse(get_config('local_spatialforumpro', 'weeklysnapshot'));
        set_config('weeklyenabled', 1, 'local_spatialforumpro');
        $task->execute();
        $snapshot = get_config('local_spatialforumpro', 'weeklysnapshot');
        $this->assertSame(
            ['forums' => 2, 'topics' => 2, 'replies' => 1, 'unanswered' => 2, 'start' => $start, 'end' => $end],
            json_decode($snapshot, true),
        );
        $DB->delete_records('spatialforum_posts', ['id' => $waiting]);
        $task->execute();
        $this->assertSame($snapshot, get_config('local_spatialforumpro', 'weeklysnapshot'));
        $this->setUser($author);
        $this->expectException(\required_capability_exception::class);
        iterator_to_array(report::rows($start, $end, (int) $course->id, (int) $forum->cmid, true));
    }

    /**
     * A delegated system report capability must not override separate groups.
     */
    public function test_delegated_report_respects_separate_groups(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $forum = $generator->create_module('spatialforum', ['course' => $course->id, 'groupmode' => SEPARATEGROUPS]);
        $DB->set_field('spatialforum', 'groupready', 1, ['id' => $forum->id]);
        $viewer = $generator->create_user();
        $author = $generator->create_user();
        $generator->enrol_user($viewer->id, $course->id, 'student');
        $generator->enrol_user($author->id, $course->id, 'student');
        $groupa = $generator->create_group(['courseid' => $course->id]);
        $groupb = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $viewer->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $author->id]);
        $context = \context_system::instance();
        $role = create_role('Delegated Pro reporter', 'proreporter', '');
        assign_capability('local/spatialforumpro:viewreport', CAP_ALLOW, $role, $context->id);
        role_assign($role, $viewer->id, $context->id);
        $visible = $this->add_post($forum, $viewer, 0, time(), groupid: (int) $groupa->id);
        $hidden = $this->add_post($forum, $author, 0, time(), groupid: (int) $groupb->id);

        $this->setUser($viewer);
        $rows = iterator_to_array(report::rows(null, filterforum: (int) $forum->cmid, moderation: true));
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['topics']);
        $this->assertSame(1, $rows[0]['unanswered']);
        $this->assertArrayHasKey($visible, $rows[0]['recentthreads']);
        $this->assertArrayNotHasKey($hidden, $rows[0]['recentthreads']);
    }

    /**
     * Generates isolated signed test material.
     *
     * @param string $wwwroot Test site URL
     * @param int|null $start Trial activation timestamp
     * @return array
     */
    private function make_trial(string $wwwroot, ?int $start = null): array {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $start ??= strtotime('2026-01-31T14:30:00Z');
        $end = licence::trial_end($start);
        $payload = json_encode([
            'version' => 1,
            'product' => 'local_spatialforumpro',
            'site' => licence::site_id($wwwroot),
            'type' => 'trial',
            'plan' => 'starter',
            'start' => $start,
            'end' => $end,
        ], JSON_THROW_ON_ERROR);
        $this->assertTrue(openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256));
        return [base64_encode($payload) . '.' . base64_encode($signature), openssl_pkey_get_details($key)['key'], $start, $end];
    }

    /**
     * Inserts a post fixture in an existing forum.
     *
     * @param \stdClass $forum Forum instance
     * @param \stdClass $author Test account
     * @param int $parent Parent post ID
     * @param int $timestamp Creation time
     * @param bool $deleted Whether the post was removed
     * @param int $groupid Group audience, or -1 for a shared post
     * @return int
     */
    private function add_post(
        \stdClass $forum,
        \stdClass $author,
        int $parent,
        int $timestamp,
        bool $deleted = false,
        int $groupid = -1,
    ): int {
        global $DB;
        $trackid = $DB->get_field('spatialforum', 'roottrackid', ['id' => $forum->id], MUST_EXIST);
        return (int) $DB->insert_record('spatialforum_posts', [
            'spatialforumid' => $forum->id,
            'groupid' => $groupid,
            'trackid' => $trackid,
            'userid' => $author->id,
            'replytoid' => $parent,
            'subject' => 'Example thread',
            'message' => 'Example post',
            'timecreated' => $timestamp,
            'deleted' => (int) $deleted,
        ]);
    }
}
