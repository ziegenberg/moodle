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

namespace mod_feedback;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/feedback/lib.php');

/**
 * Unit tests for mod_feedback_responses_table.
 *
 * @package    mod_feedback
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_feedback_responses_table::class)]
final class responses_table_test extends \advanced_testcase {
    /**
     * Creates a course with a feedback activity, a multichoice question and one response.
     *
     * @return array [feedback record, course module record]
     */
    protected function create_feedback_with_response(): array {
        $course = $this->getDataGenerator()->create_course();
        $feedback = $this->getDataGenerator()->create_module('feedback', [
            'course' => $course->id,
            'anonymous' => FEEDBACK_ANONYMOUS_NO,
        ]);
        $cm = get_coursemodule_from_instance('feedback', $feedback->id);

        /** @var \mod_feedback_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_feedback');
        $item = $generator->create_item_multichoice($feedback, ['values' => "y\nn"]);

        $user = $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'First', 'lastname' => 'Last']);
        $generator->create_response([
            'userid' => $user->id,
            'cmid' => $cm->id,
            'anonymous' => false,
            $item->name => 'y',
        ]);

        return [$feedback, $cm];
    }

    /**
     * Test that the downloaded data contains separate name columns, in the order defined by the
     * configured full name display format, and no longer contains the fullname column.
     */
    public function test_download_has_separate_name_columns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$feedback, $cm] = $this->create_feedback_with_response();

        $_GET['download'] = 'csv';
        // The output must not be buffered before the dataformat export class is instantiated, so the
        // buffer is started first. Note: the table's out() method cannot be used because the download
        // flow ends with an exit() call.
        ob_start();
        $structure = new \mod_feedback_structure($feedback, $cm, $cm->course);
        $table = new \mod_feedback_responses_table($structure);

        $columns = array_keys($table->columns);
        $this->assertSame(['firstname', 'lastname', 'groups'], array_slice($columns, 0, 3));
        $this->assertSame(['First name', 'Last name', get_string('groups')], array_slice($table->headers, 0, 3));
        $this->assertNotContains('fullname', $columns);

        $table->setup();
        $table->query_db($table->get_total_responses_count(), false);
        $table->build_table();
        $table->close_recordset();
        $output = ob_get_clean();

        $this->assertStringContainsString('"First name","Last name",Groups', $output);
        $this->assertStringContainsString('First,Last', $output);
        $this->assertStringNotContainsString('User full name', $output);
    }

    /**
     * Test that the on-screen table keeps its userpic, fullname and groups columns.
     */
    public function test_on_screen_table_keeps_fullname_column(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$feedback, $cm] = $this->create_feedback_with_response();

        unset($_GET['download']);
        $structure = new \mod_feedback_structure($feedback, $cm, $cm->course);
        $table = new \mod_feedback_responses_table($structure);

        $this->assertSame(['userpic', 'fullname', 'groups'], array_slice(array_keys($table->columns), 0, 3));
    }
}
