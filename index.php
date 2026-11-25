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
 * Displays the Spatial forum Pro report and CSV download.
 *
 * @package    local_spatialforumpro
 * @copyright  2026 Simple Solutions
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_spatialforumpro\local\licence;
use local_spatialforumpro\local\plans;
use local_spatialforumpro\local\report;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$days = optional_param('days', 30, PARAM_INT);
if (!in_array($days, [0, 7, 30, 90], true)) {
    $days = 30;
}
$download = optional_param('download', 0, PARAM_BOOL);
$page = max(0, optional_param('page', 0, PARAM_INT));
$filtercourse = max(0, optional_param('coursefilter', 0, PARAM_INT));
$filterforum = max(0, optional_param('forumfilter', 0, PARAM_INT));
$view = optional_param('view', 'participation', PARAM_ALPHA);
$moderation = $view === 'moderation';
$filters = ['days' => $days, 'coursefilter' => $filtercourse, 'forumfilter' => $filterforum];

admin_externalpage_setup('local_spatialforumpro_report', '', $filters + ['page' => $page, 'view' => $view]);
require_capability('local/spatialforumpro:viewreport', context_system::instance());

$component = 'local_spatialforumpro';
$now = time();
$key = licence::public_key();
$claims = licence::current_claims();
$users = plans::count_users();
$state = licence::state($claims, $now, $users);
$enabled = in_array($state, ['trial', 'paid'], true);
$until = $now + 1;
$since = $days === 0 ? null : $until - $days * DAYSECS;
$columns = ['course', 'forum', 'contributors', 'topics', 'replies', 'latest'];
if ($since !== null) {
    $columns[] = 'previousperiodposts';
    $columns[] = 'postchange';
}
$headings = array_map(static fn(string $column): string => get_string($column, $component), $columns);
$baseurl = new moodle_url('/local/spatialforumpro/index.php', $filters);
$viewurl = new moodle_url($baseurl, ['view' => $moderation ? 'moderation' : 'participation']);

if ($download) {
    require_sesskey();
    if (!$enabled) {
        throw new moodle_exception('reportdisabled', $component);
    }
    \core\session\manager::write_close();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="spatial-forum-participation.csv"');
    header('Cache-Control: private, no-store');
    $out = fopen('php://output', 'wb');
    fputcsv($out, array_map(report::csv_cell(...), $headings), ',', '"', '');
    foreach (report::rows($since, $until, $filtercourse, $filterforum) as $row) {
        $cells = [
            report::csv_cell($row['course']),
            report::csv_cell($row['forum']),
            $row['contributors'],
            $row['topics'],
            $row['replies'],
            $row['latest'] ? gmdate('Y-m-d H:i:s', $row['latest']) : '',
        ];
        if ($since !== null) {
            $cells[] = $row['previous'];
            $cells[] = $row['change'];
        }
        fputcsv($out, $cells, ',', '"', '');
    }
    fclose($out);
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string($moderation ? 'moderation' : 'participation', $component));
echo $OUTPUT->notification(get_string('state_' . $state, $component), $enabled ? 'info' : 'warning');
if ($claims) {
    echo html_writer::tag('p', get_string('licenceend', $component, userdate($claims['end'])));
}

if (has_capability('moodle/site:config', context_system::instance())) {
    echo html_writer::tag('p', get_string('usercount', $component, $users));
    echo html_writer::link(new moodle_url('/admin/settings.php', ['section' => $component]), get_string('licence', $component));
    if ($key === '') {
        echo $OUTPUT->notification(get_string('nokey', $component), 'warning');
    }
}

if (!$enabled) {
    echo html_writer::tag('p', get_string('purchase_marketplace', $component));
    echo html_writer::link('https://marketplace.moodle.com/plugins/local_spatialforumpro',
        get_string('purchase_marketplace_link', $component), ['target' => '_blank', 'rel' => 'noopener']);
    echo $OUTPUT->footer();
    exit;
}

$options = [];
foreach ([7, 30, 90] as $period) {
    $options[$period] = get_string('lastdays', $component, $period);
}
$options[0] = get_string('alltime', $component);
$courseoptions = [0 => get_string('allcourses', $component)];
$forumoptions = [0 => get_string('allforums', $component)];
foreach (report::forums() as [$course, $cm, $activity]) {
    $courselabel = format_string($course->fullname, true, ['context' => context_course::instance($course->id)]);
    $courseoptions[$course->id] = $courselabel;
    if (!$filtercourse || (int) $course->id === $filtercourse) {
        $forumlabel = format_string($activity->name, true, ['context' => $cm->context]);
        $forumoptions[$cm->id] = $courselabel . ' — ' . $forumlabel;
    }
}
echo $OUTPUT->single_button(new moodle_url($baseurl, ['view' => $moderation ? 'participation' : 'moderation']),
    get_string($moderation ? 'participation' : 'moderation', $component), 'get');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $viewurl->out_omit_querystring(false), 'class' => 'my-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'view', 'value' => $moderation ? 'moderation' : 'participation']);
$controls = [
    'coursefilter' => ['course', $courseoptions, $filtercourse],
    'forumfilter' => ['forum', $forumoptions, $filterforum],
];
if (!$moderation) {
    $controls['days'] = ['period', $options, $days];
}
foreach ($controls as $name => [$label, $choices, $selected]) {
    $id = 'sfpro-' . $name;
    echo html_writer::label(get_string($label, $component), $id, false, ['class' => 'me-2']);
    echo html_writer::select($choices, $name, $selected, false, ['id' => $id, 'class' => 'form-select d-inline w-auto me-3']);
}
echo html_writer::tag('button', get_string('applyfilters', $component), ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

if (!$moderation && is_siteadmin() && get_config($component, 'weeklyenabled')) {
    echo $OUTPUT->heading(get_string('weekly', $component), 3);
    $weekly = json_decode((string) get_config($component, 'weeklysnapshot'), true);
    if (is_array($weekly)) {
        $dates = (object) ['start' => gmdate('Y-m-d', (int) $weekly['start']), 'end' => gmdate('Y-m-d', (int) $weekly['end'])];
        echo html_writer::tag('p', get_string('weeklyrange', $component, $dates));
        echo html_writer::tag('p', get_string('weeklysnapshot', $component, (object) $weekly));
    } else {
        echo html_writer::tag('p', get_string('weeklyempty', $component));
    }
}

$table = new html_table();
$table->caption = get_string($moderation ? 'moderation' : 'participation', $component);
$table->head = $moderation ? array_map(static fn(string $column): string => get_string($column, $component),
    ['course', 'forum', 'unanswered', 'unansweredthreads', 'recentthreads']) : $headings;
$table->data = [];
$pagesize = 50;
$remaining = false;
foreach (report::rows($since, $until, $filtercourse, $filterforum, $moderation,
        $page * $pagesize, $pagesize + 1) as $index => $row) {
    if (count($table->data) === $pagesize) {
        $remaining = true;
        break;
    }
    $forumurl = new moodle_url('/mod/spatialforum/view.php', ['id' => $row['cmid']]);
    $cells = [
        $row['course'],
        html_writer::link($forumurl, $row['forum']),
    ];
    if ($moderation) {
        $cells[] = $row['unanswered'];
        foreach (['unansweredthreads', 'recentthreads'] as $key) {
            $links = [];
            foreach ($row[$key] as $id => $thread) {
                $url = new moodle_url($forumurl, ['trackid' => $thread['trackid'], 'postid' => $id]);
                $links[] = html_writer::link($url, s($thread['subject'])) . ' (' . userdate($thread['latest']) . ')';
            }
            $cells[] = $links ? html_writer::alist($links) : '—';
        }
    } else {
        array_push($cells, $row['contributors'], $row['topics'], $row['replies'], $row['latest'] ? userdate($row['latest']) : '—');
        if ($since !== null) {
            $cells[] = $row['previous'];
            $cells[] = $row['change'] > 0 ? '+' . $row['change'] : $row['change'];
        }
    }
    $table->data[] = $cells;
}

if ($table->data === []) {
    echo html_writer::tag('p', get_string('noforums', $component));
} else {
    echo html_writer::table($table);
}
if ($page > 0) {
    echo $OUTPUT->single_button(new moodle_url($viewurl, ['page' => $page - 1]), get_string('previous'), 'get');
}
if ($remaining) {
    echo $OUTPUT->single_button(new moodle_url($viewurl, ['page' => $page + 1]), get_string('next'), 'get');
}
if (!$moderation) {
    echo $OUTPUT->single_button(new moodle_url($baseurl, ['download' => 1]), get_string('downloadcsv', $component), 'post');
}
echo $OUTPUT->footer();
