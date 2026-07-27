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

namespace tool_database_cleaner\task;

use core\task\adhoc_task;

/**
 * Adhoc task that removes a batch of orphan course modules selected from the
 * web report.
 *
 * It delegates the per-orphan cleanup to the cleaner service (which uses the
 * core orphan-tolerant deletion). Each orphan is deleted best-effort: a cannot-clean
 * orphan (whose section is also missing) is skipped cleanly by delete_orphan's
 * section guard, so the batch continues past failures. When the batch is done it
 * messages site admins a post-run summary (cleaned / failed counts).
 *
 * @package    tool_database_cleaner
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_orphans extends adhoc_task {

    /**
     * Run the deletion batch.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $cmids = $data->cmids ?? [];

        if (empty($cmids)) {
            return;
        }

        $cleaner = new \tool_database_cleaner\cleaner();
        $summary = $cleaner->delete_orphans($cmids);

        $this->notify_admins($summary, count($cmids));
    }

    /**
     * Send the post-run summary to every site admin.
     *
     * @param \stdClass $summary The cleaner result summary.
     * @param int $requested The number of orphans the batch was asked to remove.
     */
    private function notify_admins(\stdClass $summary, int $requested): void {
        $url = new \moodle_url('/admin/tool/database_cleaner/index.php');
        $body = get_string('task_summary_body', 'tool_database_cleaner', (object)[
            'requested' => $requested,
            'cleaned' => $summary->cleanedcount,
            'failed' => $summary->failedcount,
        ]);
        $subject = get_string('task_summary_subject', 'tool_database_cleaner');

        foreach (get_admins() as $admin) {
            $message = new \core\message\message();
            $message->component = 'moodle';
            $message->name = 'notices';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $admin;
            $message->subject = $subject;
            $message->fullmessageformat = FORMAT_HTML;
            $message->notification = 1;
            $message->smallmessage = $subject;
            $message->fullmessagehtml = $body;
            $message->fullmessage = html_to_text($body);
            $message->contexturl = $url;
            $message->contexturlname = get_string('pluginname', 'tool_database_cleaner');
            message_send($message);
        }
    }
}
