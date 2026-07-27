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
 * Orphan course module report.
 *
 * A read-only report that lists every corrupt course module grouped into the
 * three detection buckets. Deletable orphans can be selected for removal; the
 * selection is posted to the confirmation page (confirm.php). Opening this page
 * performs no deletion.
 *
 * @package    tool_database_cleaner
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('tooldatabasecleaner');

$cleaner = new \tool_database_cleaner\cleaner();
$result = $cleaner->find_orphans();

// Filters (applied to every bucket).
$coursefilter = optional_param('course', 0, PARAM_INT);
$modulefilter = optional_param('module', '', PARAM_RAW);
$modulenames = get_module_types_names();
if ($modulefilter !== '' && !array_key_exists($modulefilter, $modulenames)) {
    $modulefilter = '';
}

// Bulk-fetch display data for the courses and sections referenced by the report.
$coursedisplay = [];
$sectiondisplay = [];
$allcourseids = [];
$allsectionids = [];
foreach ($result as $bucket => $rows) {
    foreach ($rows as $row) {
        $allcourseids[] = $row->course;
        $allsectionids[] = $row->section;
    }
}
if ($allcourseids) {
    $allcourseids = array_unique($allcourseids);
    foreach ($DB->get_records_list('course', 'id', $allcourseids, '', 'id, shortname, fullname') as $c) {
        $coursedisplay[$c->id] = $c;
    }
}
if ($allsectionids) {
    $allsectionids = array_unique($allsectionids);
    foreach ($DB->get_records_list('course_sections', 'id', $allsectionids, '', 'id, section, name') as $s) {
        $sectiondisplay[$s->id] = $s;
    }
}

/**
 * Apply the current filters to a bucket's rows.
 */
$applyfilters = function (array $rows) use ($coursefilter, $modulefilter): array {
    $filtered = array_filter($rows, function ($row) use ($coursefilter, $modulefilter) {
        if ($coursefilter && $row->course != $coursefilter) {
            return false;
        }
        if ($modulefilter !== '' && $row->modulename !== $modulefilter) {
            return false;
        }
        return true;
    });
    return array_values($filtered);
};

$deletable = $applyfilters($result[\tool_database_cleaner\cleaner::BUCKET_DELETABLE]);
$cannotverify = $applyfilters($result[\tool_database_cleaner\cleaner::BUCKET_CANNOT_VERIFY]);
$cannotclean = $applyfilters($result[\tool_database_cleaner\cleaner::BUCKET_CANNOT_CLEAN]);
$total = count($deletable) + count($cannotverify) + count($cannotclean);

/**
 * Build a display cell value for a course id.
 */
$coursecell = function ($courseid) use ($coursedisplay): string {
    if (!empty($coursedisplay[$courseid])) {
        $c = $coursedisplay[$courseid];
        return s($c->shortname) . ' (' . $c->id . ')';
    }
    return get_string('missing', 'tool_database_cleaner') . ' (' . $courseid . ')';
};
$sectioncell = function ($sectionid) use ($sectiondisplay): string {
    if (!empty($sectiondisplay[$sectionid])) {
        $s = $sectiondisplay[$sectionid];
        $name = trim((string) $s->name) !== '' ? format_string($s->name) : get_string('section', 'tool_database_cleaner') . ' ' . $s->section;
        return s($name) . ' (' . $s->id . ')';
    }
    return get_string('missing', 'tool_database_cleaner') . ' (' . $sectionid . ')';
};

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report_heading', 'tool_database_cleaner'));

if ($total === 0) {
    echo html_writer::div(get_string('report_no_orphans', 'tool_database_cleaner'), 'alert alert-success');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::div(get_string('report_intro', 'tool_database_cleaner'), 'lead');

// --- Filter form (GET, posts back to this page). ---
$filterurl = new moodle_url('/admin/tool/database_cleaner/index.php');
$filterform = html_writer::start_tag('form', ['method' => 'get', 'action' => $filterurl->out_omit_querystring(), 'class' => 'form-inline']);
$filterform .= html_writer::input_hidden_params($filterurl, ['course', 'module']);
$filterform .= html_writer::label(get_string('filter_course', 'tool_database_cleaner'), 'menucourse', false, ['class' => 'me-2']);
$filterform .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'course', 'id' => 'menucourse', 'value' => $coursefilter, 'size' => 8]);
$filterform .= html_writer::label(get_string('filter_module', 'tool_database_cleaner'), 'menumodule', false, ['class' => 'ms-3 me-2']);
$moduleoptions = ['' => get_string('all')] + $modulenames;
$filterform .= html_writer::select($moduleoptions, 'module', $modulefilter, false, ['id' => 'menumodule']);
$filterform .= html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'), 'class' => 'ms-3 btn btn-secondary']);
$filterform .= html_writer::end_tag('form');
echo html_writer::div($filterform, 'mb-4 mt-2');

/**
 * Render a bucket as a table. If $selectable is true, rows are wrapped in a form
 * with per-row checkboxes (checked by default) and a submit button.
 */
$renderbucket = function (string $titlekey, array $rows, bool $selectable) use ($coursecell, $sectioncell, $modulenames): void {
    global $OUTPUT;
    echo $OUTPUT->heading(get_string($titlekey, 'tool_database_cleaner') . ' (' . count($rows) . ')', 3);

    if (empty($rows)) {
        echo html_writer::div(get_string('bucket_empty', 'tool_database_cleaner'), 'mb-4');
        return;
    }

    $table = new html_table();
    $table->head = [
        get_string('cmid', 'tool_database_cleaner'),
        get_string('course', 'moodle'),
        get_string('section', 'moodle'),
        get_string('module', 'tool_database_cleaner'),
        get_string('instance', 'tool_database_cleaner'),
        get_string('added', 'tool_database_cleaner'),
    ];
    if ($selectable) {
        array_unshift($table->head, get_string('select', 'tool_database_cleaner'));
    }
    $table->data = [];
    foreach ($rows as $row) {
        $modulename = $modulenames[$row->modulename] ?? $row->modulename;
        $cells = [
            $row->id,
            $coursecell($row->course),
            $sectioncell($row->section),
            s($modulename),
            $row->instance,
            $row->added ? userdate($row->added, get_string('strftimedatetime', 'langconfig')) : '-',
        ];
        if ($selectable) {
            $checkbox = html_writer::checkbox('cmids[]', $row->id, true, '',
                ['class' => 'tool-database-cleaner-cmselect']);
            array_unshift($cells, $checkbox);
        }
        $table->data[] = new html_table_row($cells);
    }

    if ($selectable) {
        $confirmurl = new moodle_url('/admin/tool/database_cleaner/confirm.php');
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $confirmurl->out_omit_querystring()]);
        echo html_writer::table($table);
        echo html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => get_string('confirm_delete_selected', 'tool_database_cleaner'),
            'class' => 'btn btn-primary mt-2',
        ]);
        echo html_writer::end_tag('form');
    } else {
        echo html_writer::table($table);
    }
    echo html_writer::div('', 'mb-4');
};

$renderbucket('bucket_deletable', $deletable, true);
$renderbucket('bucket_cannotverify', $cannotverify, false);
$renderbucket('bucket_cannotclean', $cannotclean, false);

echo $OUTPUT->footer();
