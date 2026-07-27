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
 * Tests for the orphan course module detection (tool_database_cleaner\cleaner).
 *
 * @package    tool_database_cleaner
 * @category   test
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_database_cleaner\cleaner::class)]
final class cleaner_test extends \advanced_testcase {

    /**
     * find_orphans classifies corrupt course modules into the correct buckets and
     * ignores half-created (instance = 0) modules.
     */
    public function test_find_orphans_classifies_three_buckets(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('coursebinenable', false, 'tool_recyclebin');

        $generator = $this->getDataGenerator();

        // --- Deletable orphan: instance row missing, course and section intact. ---
        $course1 = $generator->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $deletable = $generator->create_module('assign', ['course' => $course1, 'section' => 1]);
        $DB->delete_records('assign', ['id' => $deletable->id]);

        // --- Cannot-clean orphan: instance row missing AND section missing. ---
        $course2 = $generator->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $cannotclean = $generator->create_module('assign', ['course' => $course2, 'section' => 1]);
        $DB->delete_records('assign', ['id' => $cannotclean->id]);
        $section = $DB->get_record('course_sections', ['course' => $course2->id, 'section' => 1], '*', MUST_EXIST);
        $DB->delete_records('course_sections', ['id' => $section->id]);

        // --- Cannot-verify: module installed in mdl_modules but its activity table is missing. ---
        // Clone a real course_modules row and repoint it at a fake module with no table.
        $fakeid = $DB->insert_record('modules', (object)[
            'name' => 'zzfakeghost', 'search' => '', 'visible' => 0,
        ]);
        $realcm = $DB->get_record('course_modules', ['id' => $deletable->cmid], '*', MUST_EXIST);
        $fakecm = clone($realcm);
        unset($fakecm->id);
        $fakecm->module = $fakeid;
        $fakecm->instance = 999;
        $fakecmid = $DB->insert_record('course_modules', $fakecm, true);

        // --- Out of scope: instance = 0 (half-created module) must not be detected. ---
        $instancezero = $generator->create_module('assign', ['course' => $course1, 'section' => 1]);
        $DB->set_field('course_modules', 'instance', 0, ['id' => $instancezero->cmid]);

        $cleaner = new cleaner();
        $result = $cleaner->find_orphans();

        // Bucket keys present.
        $this->assertSame(['deletable', 'cannotverify', 'cannotclean'], array_keys($result));

        // Deletable: only the deletable orphan.
        $deletableids = array_column($result['deletable'], 'id');
        $this->assertContains($deletable->cmid, $deletableids);
        $this->assertNotContains($cannotclean->cmid, $deletableids);
        $this->assertNotContains($instancezero->cmid, $deletableids);

        // Cannot-clean: the orphan whose section is gone.
        $cannotcleanids = array_column($result['cannotclean'], 'id');
        $this->assertContains($cannotclean->cmid, $cannotcleanids);
        $this->assertNotContains($deletable->cmid, $cannotcleanids);

        // Cannot-verify: the course module whose activity table is missing.
        $cannotverifyids = array_column($result['cannotverify'], 'id');
        $this->assertContains($fakecmid, $cannotverifyids);

        // The half-created (instance = 0) module appears in no bucket.
        $all = array_merge(
            array_column($result['deletable'], 'id'),
            array_column($result['cannotverify'], 'id'),
            array_column($result['cannotclean'], 'id'),
        );
        $this->assertNotContains($instancezero->cmid, $all);

        // Each reported row carries its classification and identifying data.
        $deletablerow = $result['deletable'][0];
        $this->assertSame('deletable', $deletablerow->bucket);
        $this->assertSame('assign', $deletablerow->modulename);
        $this->assertEquals($deletable->id, $deletablerow->instance);
    }

    /**
     * A clean site (no corruption) produces three empty buckets.
     */
    public function test_find_orphans_clean_site(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $generator->create_module('assign', ['course' => $course, 'section' => 1]);

        $result = (new cleaner())->find_orphans();
        $this->assertEmpty($result['deletable']);
        $this->assertEmpty($result['cannotverify']);
        $this->assertEmpty($result['cannotclean']);
    }

    /**
     * delete_orphans removes deletable orphans via core delete_orphan, and a
     * failing (cannot-clean) orphan is skipped cleanly with no partial state while
     * the batch continues. Moodle's delegated transactions are all-or-nothing so
     * per-orphan rollback is impossible; delete_orphan guards against a missing
     * section before any destructive work, which is what makes the skip clean.
     */
    public function test_delete_orphans_continues_on_failure(): void {
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

        $sink = $this->redirectEvents();

        // Pass the cannot-clean id in the middle to prove the batch continues past it.
        $summary = (new cleaner())->delete_orphans([$assigna->cmid, $assignc->cmid, $assignb->cmid]);

        $events = $sink->get_events();
        $sink->close();

        // The two deletable orphans were removed.
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $assigna->cmid]));
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $assignb->cmid]));
        // The cannot-clean orphan was skipped (section guard) and is still present.
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $assignc->cmid]));

        // Summary counts.
        $this->assertSame(2, $summary->cleanedcount);
        $this->assertSame(1, $summary->failedcount);
        $this->assertContains($assigna->cmid, $summary->cleaned);
        $this->assertContains($assignb->cmid, $summary->cleaned);
        $this->assertArrayHasKey($assignc->cmid, $summary->failed);

        // A deletion event fired for each cleaned orphan, none for the failed one.
        $cleanedids = [];
        foreach ($events as $event) {
            if ($event instanceof \core\event\course_module_deleted) {
                $cleanedids[] = (int)$event->objectid;
            }
        }
        $this->assertContains($assigna->cmid, $cleanedids);
        $this->assertContains($assignb->cmid, $cleanedids);
        $this->assertNotContains($assignc->cmid, $cleanedids);
    }
}
