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
 * Adds the reports and licence pages to Moodle administration.
 *
 * @package    local_spatialforumpro
 * @copyright  2026 Simple Solutions
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$ADMIN->add('reports', new admin_externalpage(
    'local_spatialforumpro_report',
    get_string('participation', 'local_spatialforumpro'),
    new moodle_url('/local/spatialforumpro/index.php'),
    'local/spatialforumpro:viewreport',
));

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_spatialforumpro', get_string('pluginname', 'local_spatialforumpro'));
    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configtextarea(
            'local_spatialforumpro/licence',
            get_string('licence', 'local_spatialforumpro'),
            get_string('licence_desc', 'local_spatialforumpro', \local_spatialforumpro\local\licence::site_id($CFG->wwwroot)),
            '',
            PARAM_RAW_TRIMMED,
            60,
            4,
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_spatialforumpro/weeklyenabled',
            get_string('weeklyenabled', 'local_spatialforumpro'),
            get_string('weeklyenabled_desc', 'local_spatialforumpro'),
            0,
        ));
    }
    $ADMIN->add('localplugins', $settings);
}
