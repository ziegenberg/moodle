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

namespace core_courseformat;

/**
 * Tests for the orphan-tolerant course module deletion (cmactions::delete_orphan).
 *
 * @package    core_courseformat
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\core_courseformat\local\cmactions::class)]
final class cmactions_delete_orphan_test extends \advanced_testcase {

    /**
     * A deleted activity instance turns the course module into an orphan that
     * delete_orphan can fully clean up, including the surrounding data and the
     * course_module_deleted event.
     */
    public function test_delete_orphan_removes_orphan_and_fires_event(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        // Disable recyclebin so its pre_course_module_delete hook does not interfere.
        set_config('coursebinenable', false, 'tool_recyclebin');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $assign = $generator->create_module('assign', ['course' => $course, 'section' => 1]);

        $cmid = $assign->cmid;
        $instanceid = $assign->id;
        $modcontext = \context_module::instance($cmid);

        // Add a file to the module context to prove the surrounding file cleanup runs.
        $fs = get_file_storage();
        $filerecord = (object)[
            'contextid' => $modcontext->id,
            'component'  => 'mod_assign',
            'filearea'   => 'intro',
            'itemid'     => 0,
            'filepath'   => '/',
            'filename'   => 'orphan.txt',
        ];
        $fs->create_file_from_string($filerecord, 'leftover');
        $this->assertTrue($DB->record_exists('files', [
            'contextid' => $modcontext->id, 'component' => 'mod_assign', 'filename' => 'orphan.txt',
        ]));

        // Turn the module into an orphan by removing its activity instance row directly.
        $DB->delete_records('assign', ['id' => $instanceid]);
        $this->assertFalse($DB->record_exists('assign', ['id' => $instanceid]));
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $cmid]));

        // Capture events.
        $sink = $this->redirectEvents();

        formatactions::cm($course->id)->delete_orphan($cmid);

        // The course module row and its context are gone.
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $cmid]));
        $this->assertFalse($DB->record_exists('context', [
            'contextlevel' => CONTEXT_MODULE, 'instanceid' => $cmid,
        ]));
        // The surrounding files have been cleaned up.
        $this->assertFalse($DB->record_exists('files', [
            'contextid' => $modcontext->id, 'component' => 'mod_assign', 'filename' => 'orphan.txt',
        ]));
        // The module is no longer referenced in its section sequence.
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1], '*', MUST_EXIST);
        $sequence = array_filter(explode(',', $section->sequence));
        $this->assertNotContains($cmid, $sequence);

        // The standard course_module_deleted event fired for the orphan.
        $events = $sink->get_events();
        $sink->close();
        $found = false;
        foreach ($events as $event) {
            if ($event instanceof \core\event\course_module_deleted && $event->objectid == $cmid) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected a course_module_deleted event for the orphan.');
    }

    /**
     * delete_orphan on a non-existent course module id is a safe no-op.
     */
    public function test_delete_orphan_nonexistent_is_noop(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        // Should not throw.
        formatactions::cm($course->id)->delete_orphan(999999998);

        $this->assertDebuggingNotCalled();
    }

    /**
     * delete_orphan refuses a cannot-clean orphan (section also missing) BEFORE any
     * destructive work, leaving no partial state behind. This is what lets a batch
     * caller skip it and continue.
     */
    public function test_delete_orphan_refuses_cannot_clean_orphan_without_partial_state(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('coursebinenable', false, 'tool_recyclebin');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $assign = $generator->create_module('assign', ['course' => $course, 'section' => 1]);
        $cmid = $assign->cmid;

        // Turn it into a cannot-clean orphan: instance gone AND section gone.
        $DB->delete_records('assign', ['id' => $assign->id]);
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1], '*', MUST_EXIST);
        $DB->delete_records('course_sections', ['id' => $section->id]);

        try {
            formatactions::cm($course->id)->delete_orphan($cmid);
            $this->fail('Expected delete_orphan to refuse a cannot-clean orphan.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('cannotdeletemodulefromsection', $e->getMessage());
        }

        // No partial state: the course module row and its context are untouched.
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $cmid]));
        $this->assertTrue($DB->record_exists('context', [
            'contextlevel' => CONTEXT_MODULE, 'instanceid' => $cmid,
        ]));
    }

    /**
     * The normal delete() path still refuses an orphan (missing instance) - its
     * contract is unchanged by the introduction of delete_orphan.
     */
    public function test_delete_still_throws_on_missing_instance(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('coursebinenable', false, 'tool_recyclebin');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $assign = $generator->create_module('assign', ['course' => $course, 'section' => 1]);
        $cmid = $assign->cmid;

        // Turn it into an orphan.
        $DB->delete_records('assign', ['id' => $assign->id]);

        $this->expectException(\moodle_exception::class);
        formatactions::cm($course->id)->delete($cmid);
    }
}
