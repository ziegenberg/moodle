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

namespace core;

use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Tests for the deprecated MNet functions.
 *
 * @package   core
 * @category  test
 * @copyright 2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('get_my_remotecourses')]
#[CoversFunction('get_my_remotehosts')]
final class mnet_deprecated_functions_test extends \advanced_testcase {
    /**
     * Test that get_my_remotecourses() is deprecated and returns no courses.
     */
    public function test_get_my_remotecourses_is_deprecated(): void {
        $this->resetAfterTest();

        // The function is deprecated and always returns an empty list, as the
        // mnetservice_enrol plugin and its tables were removed from core.
        $this->assertSame([], get_my_remotecourses());
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);

        // The userid argument is still accepted for backwards compatibility, but is ignored.
        $this->assertSame([], get_my_remotecourses(123));
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }

    /**
     * Test that get_my_remotehosts() is deprecated.
     */
    public function test_get_my_remotehosts_is_deprecated(): void {
        $this->resetAfterTest();

        $this->assertFalse(get_my_remotehosts());
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }
}
