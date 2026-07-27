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

use core\task\scheduled_task;

/**
 * Scheduled task that reports orphan course modules to site admins.
 *
 * It runs the cleaner's detection on cron. When one or more orphans are found
 * it messages site admins pointing them at the report. It NEVER deletes
 * anything; deletion always requires explicit confirmation on the web report.
 * When zero orphans are found, no message is sent.
 *
 * @package    tool_database_cleaner
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_orphans extends scheduled_task {

    /**
     * Get a descriptive name for this task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskreportorphans', 'tool_database_cleaner');
    }

    /**
     * Run the detection and notify admins when orphans are found.
     */
    public function execute(): void {
        $cleaner = new \tool_database_cleaner\cleaner();
        $result = $cleaner->find_orphans();

        $total = count($result['deletable'])
            + count($result['cannotverify'])
            + count($result['cannotclean']);

        if ($total === 0) {
            // Nothing to report; do not spam admins.
            return;
        }

        $url = new \moodle_url('/admin/tool/database_cleaner/index.php');
        $body = get_string('task_report_body', 'tool_database_cleaner', $total);
        $subject = get_string('task_report_subject', 'tool_database_cleaner');

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
