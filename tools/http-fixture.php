<?php
// Synthetic fixtures used only in the isolated QA Moodle installation.

if (PHP_SAPI !== 'cli' || !str_starts_with(__DIR__, '/tmp/sfpro-moodle/')) {
    exit(1);
}

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

\core\cron::setup_user();
$generator = \core\test\testing_util::get_data_generator();
$course = $generator->create_course(['fullname' => 'Collaborative learning']);
$forum = $generator->create_module('spatialforum', ['course' => $course->id, 'name' => 'Design discussion']);
$learner = $generator->create_user(['username' => 'qa-learner', 'password' => 'Qa-Disposable-04!']);
$generator->enrol_user($learner->id, $course->id, 'student');
$trackid = $DB->get_field('spatialforum', 'roottrackid', ['id' => $forum->id], MUST_EXIST);
[$start] = \local_spatialforumpro\task\weekly_report::previous_week(time());
$DB->insert_record('spatialforum_posts', [
    'spatialforumid' => $forum->id,
    'trackid' => $trackid,
    'userid' => $learner->id,
    'subject' => 'QA unanswered thread',
    'message' => 'Synthetic example for the review environment.',
    'timecreated' => $start + HOURSECS,
]);
set_config('weeklyenabled', 1, 'local_spatialforumpro');
echo (int) $forum->cmid . PHP_EOL;
