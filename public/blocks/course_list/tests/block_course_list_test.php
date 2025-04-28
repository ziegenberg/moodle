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

namespace block_course_list;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the block_course_list class.
 *
 * @package    block_course_list
 * @category   test
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\block_course_list::class)]
final class block_course_list_test extends \advanced_testcase {
    public static function setUpBeforeClass(): void {
        global $CFG;

        require_once(__DIR__ . '/../../moodleblock.class.php');
        // The block_course_list.php uses $CFG at include time.
        require_once(__DIR__ . '/../block_course_list.php');
        parent::setUpBeforeClass();
    }

    /**
     * Test that the deprecated get_remote_courses() method emits a deprecation notice.
     */
    public function test_get_remote_courses_is_deprecated(): void {
        $this->resetAfterTest();

        $block = new \block_course_list();
        $this->assertNull($block->get_remote_courses());
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }
}
