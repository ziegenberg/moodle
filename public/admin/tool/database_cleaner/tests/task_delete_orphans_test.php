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
 * Tests for the adhoc deletion task (tool_database_cleaner\task\delete_orphans).
 *
 * @package    tool_database_cleaner
 * @category   test
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_database_cleaner\task\delete_orphans::class)]
final class task_delete_orphans_test extends \advanced_testcase {

    /**
     * The adhoc task removes deletable orphans, rolls back an uncleanable one,
     * continues the batch, and messages admins a post-run summary.
     */
    public function test_execute_deletes_deletable_and_continues_on_failure(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('coursebinenable', false, 'tool_recyclebin');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2, 'format' => 'topics'], ['createsections' => true]);

        // Two deletable orphans (sections 1 and 2 stay intact).
        $assigna = $generator->create_module('assign', ['course' => $course, 'section' => 1]);
        $assignb = $generator->create_module('assign', ['course' => $course, 'section' => 2]);
        $DB->delete_records('assign', ['id' => $assigna->id]);
        $DB->delete_records('assign', ['id' => $assignb->id]);

        // One cannot-clean orphan in a SEPARATE course, so deleting its section
        // does not affect the deletable orphans above.
        $cannotcleancourse = $generator->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $assignc = $generator->create_module('assign', ['course' => $cannotcleancourse, 'section' => 1]);
        $DB->delete_records('assign', ['id' => $assignc->id]);
        $section = $DB->get_record('course_sections', ['course' => $cannotcleancourse->id, 'section' => 1], '*', MUST_EXIST);
        $DB->delete_records('course_sections', ['id' => $section->id]);

        $sink = $this->redirectMessages();

        $task = new \tool_database_cleaner\task\delete_orphans();
        $task->set_custom_data((object)[
            'cmids' => [$assigna->cmid, $assignc->cmid, $assignb->cmid],
            'userid' => get_admin()->id,
        ]);
        $task->execute();

        // Deletable orphans removed; cannot-clean orphan skipped (section guard) and still present.
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $assigna->cmid]));
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $assignb->cmid]));
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $assignc->cmid]));

        // A post-run summary message was sent to the admin.
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertNotEmpty($messages);
        $body = $messages[0]->fullmessagehtml ?? '';
        $this->assertStringContainsString('Cleaned: 2', $body);
        $this->assertStringContainsString('Failed: 1', $body);
    }

    /**
     * An empty selection is a no-op (no deletion, no message).
     */
    public function test_execute_empty_selection_is_noop(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $sink = $this->redirectMessages();

        $task = new \tool_database_cleaner\task\delete_orphans();
        $task->set_custom_data((object)['cmids' => [], 'userid' => get_admin()->id]);
        $task->execute();

        $this->assertEmpty($sink->get_messages());
        $sink->close();
    }
}
