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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/rsslib.php');

/**
 * Tests for the RSS functions in lib/rsslib.php.
 *
 * @package    core
 * @category   test
 * @copyright  2009 Dan Poltawski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rsslib_test extends \advanced_testcase {

    /**
     * Test that we can get the right user ID based on the provided private key (token).
     *
     * @covers ::rss_get_userid_from_token
     */
    public function test_rss_get_userid_from_token(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setGuestUser();

        $key = rss_get_token($USER->id);
        $this->assertSame(rss_get_userid_from_token($key), $USER->id);
    }
}
