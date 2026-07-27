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

namespace tool_database_cleaner;

/**
 * Tests for the scheduled report task (tool_database_cleaner\task\report_orphans).
 *
 * @package    tool_database_cleaner
 * @category   test
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_database_cleaner\task\report_orphans::class)]
final class task_report_orphans_test extends \advanced_testcase {

    /**
     * When orphans are found, the task messages site admins and deletes nothing.
     */
    public function test_reports_when_orphans_found(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $assign = $generator->create_module('assign', ['course' => $course, 'section' => 1]);
        $DB->delete_records('assign', ['id' => $assign->id]);

        $sink = $this->redirectMessages();

        (new \tool_database_cleaner\task\report_orphans())->execute();

        // A message was sent to the admin.
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('1', $messages[0]->fullmessagehtml ?? '');

        // The task never deletes: the orphan is still there.
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $assign->cmid]));
    }

    /**
     * When the site is clean, the task sends no message.
     */
    public function test_no_message_when_clean(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $generator->create_module('assign', ['course' => $course, 'section' => 1]);

        $sink = $this->redirectMessages();

        (new \tool_database_cleaner\task\report_orphans())->execute();

        $this->assertEmpty($sink->get_messages());
        $sink->close();
    }
}
