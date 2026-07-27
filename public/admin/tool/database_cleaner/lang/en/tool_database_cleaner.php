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
 * English language pack for Database cleaner
 *
 * @package    tool_database_cleaner
 * @category   string
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Database cleaner';
$string['privacy:metadata'] = 'The Database cleaner plugin doesn\'t store any personal data.';

// Orphan course module report buckets.
$string['bucket_deletable'] = 'Deletable orphans';
$string['bucket_cannotverify'] = 'Cannot verify (activity table missing)';
$string['bucket_cannotclean'] = 'Cannot clean (course or section missing)';
$string['bucket_empty'] = 'None found.';
$string['summary_total'] = 'Total corrupt course modules found: {$a}';
$string['report_no_orphans'] = 'No orphan course modules were detected. The database looks clean.';

// Deletion (CLI --fix and web confirm).
$string['error_cm_not_found'] = 'Course module not found (already deleted).';
$string['fix_nothing_to_delete'] = 'Nothing to delete: no deletable orphans were found.';
$string['fix_starting'] = 'Removing {$a} deletable orphan(s)...';
$string['fix_cleaned'] = 'Cleaned course module {$a}.';
$string['fix_failed'] = 'Failed to clean course module {$a->id}: {$a->error}';
$string['fix_summary'] = 'Done. Cleaned: {$a->cleaned}. Failed: {$a->failed}.';

// Web report.
$string['report_heading'] = 'Orphan course module report';
$string['report_intro'] = 'Corrupt course modules are grouped into three buckets. Only the deletable orphans can be removed; the others are reported for information and are never deleted. Removing orphans is irreversible.';
$string['cmid'] = 'CM id';
$string['module'] = 'Module';
$string['instance'] = 'Instance id';
$string['added'] = 'Added';
$string['select'] = 'Select';
$string['section'] = 'Section';
$string['missing'] = 'Missing';
$string['filter_course'] = 'Course id';
$string['filter_module'] = 'Module type';
$string['confirm_delete_selected'] = 'Review selected for deletion';

// Web confirmation page.
$string['confirm_heading'] = 'Confirm orphan deletion';
$string['confirm_warning'] = 'You are about to permanently remove the orphan course modules listed below. This action is irreversible. The deletion will run as a background task; you will receive a message with the results when it completes.';
$string['confirm_ack'] = 'I understand this action is irreversible and I want to remove these orphan course modules.';
$string['confirm_ack_required'] = 'You must acknowledge that the action is irreversible.';
$string['confirm_button'] = 'Delete the selected orphans';
$string['confirm_nothing'] = 'No deletable orphans were selected.';
$string['confirm_queued'] = 'The deletion has been scheduled as a background task. You will receive a message with the results when it completes.';

// Adhoc deletion task summary message.
$string['taskdeleteorphans'] = 'Delete orphan course modules (background)';
$string['task_summary_subject'] = 'Orphan course module deletion complete';
$string['task_summary_body'] = 'The background deletion of orphan course modules has completed. Requested: {$a->requested}. Cleaned: {$a->cleaned}. Failed: {$a->failed}.';

// Scheduled report task.
$string['taskreportorphans'] = 'Report orphan course modules';
$string['task_report_subject'] = 'Orphan course modules detected';
$string['task_report_body'] = 'The site has {$a} orphan course module(s) requiring attention. Review and remove them in the Database cleaner tool.';
