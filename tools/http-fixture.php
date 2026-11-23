<?php
// Synthetic fixtures used only in the isolated QA Moodle installation.

if (PHP_SAPI !== 'cli' || !str_starts_with(__DIR__, '/tmp/sfpro-moodle/')) {
    exit(1);
}

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

\core\cron::setup_user();
if (class_exists(\core\test\testing_util::class)) {
    $generator = \core\test\testing_util::get_data_generator();
} else {
    require_once($CFG->libdir . '/testing/generator/lib.php');
    $generator = new testing_data_generator();
}
$course = $generator->create_course(['fullname' => 'Collaborative learning']);
$forum = $generator->create_module('spatialforum', ['course' => $course->id, 'name' => 'Design discussion']);
$learner = $generator->create_user(['username' => 'qa-learner', 'password' => 'Qa-Disposable-04!']);
$generator->enrol_user($learner->id, $course->id, 'student');
$trackid = $DB->get_field('spatialforum', 'roottrackid', ['id' => $forum->id], MUST_EXIST);
[$start] = \local_spatialforumpro\task\weekly_report::previous_week(time());
$browser = getenv('SFPRO_BROWSER') === '1';
$DB->insert_record('spatialforum_posts', [
    'spatialforumid' => $forum->id,
    'trackid' => $trackid,
    'userid' => $learner->id,
    'subject' => $browser ? 'Which prototype should we test first?' : 'QA unanswered thread',
    'message' => 'Synthetic example for the review environment.',
    'timecreated' => $start + HOURSECS,
]);
if ($browser) {
    $second = $generator->create_course(['fullname' => 'Learning design studio']);
    $examples = [
        [$course, 'Peer feedback', 'How can we make feedback more actionable?'],
        [$second, 'Project retrospective', 'What would we change for the next workshop?'],
        [$second, 'Workshop planning', 'Which topics should we prioritise?'],
    ];
    foreach ($examples as [$examplecourse, $name, $subject]) {
        $example = $generator->create_module('spatialforum', ['course' => $examplecourse->id, 'name' => $name]);
        $exampletrack = $DB->get_field('spatialforum', 'roottrackid', ['id' => $example->id], MUST_EXIST);
        $post = [
            'spatialforumid' => $example->id,
            'trackid' => $exampletrack,
            'userid' => $learner->id,
            'subject' => $subject,
            'message' => 'Synthetic content for the Spatial forum Pro demo.',
            'timecreated' => $start + 2 * DAYSECS,
        ];
        $parent = $DB->insert_record('spatialforum_posts', $post);
        if ($name !== 'Workshop planning') {
            $post['replytoid'] = $parent;
            $post['userid'] = $USER->id;
            $post['timecreated'] += HOURSECS;
            $DB->insert_record('spatialforum_posts', $post);
        }
        $DB->insert_record('spatialforum_posts', [
            'spatialforumid' => $example->id,
            'trackid' => $exampletrack,
            'userid' => $learner->id,
            'subject' => 'Previous discussion',
            'message' => 'Synthetic content for comparison with the previous period.',
            'timecreated' => $start - 35 * DAYSECS,
        ]);
    }
}
set_config('weeklyenabled', 1, 'local_spatialforumpro');
echo (int) $forum->cmid . PHP_EOL;
