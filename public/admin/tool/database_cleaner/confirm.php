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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Confirmation page for orphan course module deletion.
 *
 * The course modules selected on the report are re-verified as deletable, listed
 * for a final review, and removed only after the administrator explicitly
 * acknowledges the action is irreversible. The deletion itself runs as a
 * background adhoc task.
 *
 * @package    tool_database_cleaner
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('tooldatabasecleaner');

$returnurl = new moodle_url('/admin/tool/database_cleaner/index.php');

$cmids = required_param_array('cmids', PARAM_INT);
$cmids = array_values(array_unique(array_map('intval', $cmids)));

// Re-verify the selection against the current deletable set so we never enqueue a
// cannot-verify or cannot-clean course module.
$cleaner = new \tool_database_cleaner\cleaner();
$deletableids = array_column($cleaner->find_orphans()['deletable'], 'id');
$todelete = array_values(array_intersect($cmids, $deletableids));

if (empty($todelete)) {
    redirect($returnurl, get_string('confirm_nothing', 'tool_database_cleaner'),
        null, \core\output\notification::NOTIFY_WARNING);
}

$confirmed = optional_param('confirmed', 0, PARAM_BOOL);

if ($confirmed) {
    require_sesskey();
    $ack = optional_param('ack', 0, PARAM_BOOL);
    if (!$ack) {
        redirect(new moodle_url('/admin/tool/database_cleaner/confirm.php', []),
            get_string('confirm_ack_required', 'tool_database_cleaner'),
            null, \core\output\notification::NOTIFY_ERROR);
    }

    // Enqueue the background deletion task.
    $task = new \tool_database_cleaner\task\delete_orphans();
    $task->set_custom_data([
        'cmids' => $todelete,
        'userid' => $USER->id,
    ]);
    \core\task\manager::queue_adhoc_task($task);

    redirect($returnurl, get_string('confirm_queued', 'tool_database_cleaner'),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

// --- Show the confirmation form. ---

// Fetch the selected course module rows, and their course / module in bulk for display.
$cmrows = $DB->get_records_list('course_modules', 'id', $todelete);
$moduleids = array_values(array_unique(array_map(fn($cm) => $cm->module, $cmrows)));
$modulesbyname = [];
if ($moduleids) {
    foreach ($DB->get_records_list('modules', 'id', $moduleids, '', 'id, name') as $m) {
        $modulesbyname[$m->id] = $m->name;
    }
}
$courseids = array_values(array_unique(array_map(fn($cm) => $cm->course, $cmrows)));
$courses = [];
if ($courseids) {
    foreach ($DB->get_records_list('course', 'id', $courseids, '', 'id, shortname, fullname') as $c) {
        $courses[$c->id] = $c;
    }
}
$modulenames = get_module_types_names();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('confirm_heading', 'tool_database_cleaner'));

echo html_writer::div(get_string('confirm_warning', 'tool_database_cleaner'), 'alert alert-danger');

$table = new html_table();
$table->head = [
    get_string('cmid', 'tool_database_cleaner'),
    get_string('course', 'moodle'),
    get_string('module', 'tool_database_cleaner'),
    get_string('instance', 'tool_database_cleaner'),
];
$table->data = [];
foreach ($todelete as $cmid) {
    $cm = $cmrows[$cmid] ?? null;
    $modulename = $cm ? ($modulesbyname[$cm->module] ?? '?') : '?';
    $modulename = $modulenames[$modulename] ?? $modulename;
    $courselabel = ($cm && !empty($courses[$cm->course]))
        ? s($courses[$cm->course]->shortname) . ' (' . $cm->course . ')'
        : (string)($cm->course ?? '');
    $table->data[] = new html_table_row([
        $cmid,
        $courselabel,
        s($modulename),
        $cm->instance ?? '',
    ]);
}
echo html_writer::table($table);

$confirmurl = new moodle_url('/admin/tool/database_cleaner/confirm.php');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $confirmurl->out_omit_querystring(), 'class' => 'mt-3']);
foreach ($todelete as $cmid) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmids[]', 'value' => $cmid]);
}
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirmed', 'value' => '1']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::div(
    html_writer::checkbox('ack', 1, false, ' ' . get_string('confirm_ack', 'tool_database_cleaner'),
        ['id' => 'tool_database_cleaner_ack', 'required' => 'required']),
    'mb-3'
);
echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string('confirm_button', 'tool_database_cleaner'),
    'class' => 'btn btn-danger',
]);
echo ' ';
echo html_writer::link($returnurl, get_string('cancel'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
