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

/**
 * Behat step definitions for tool_database_cleaner.
 *
 * @package    tool_database_cleaner
 * @category   test
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

/**
 * Step definitions for tool_database_cleaner.
 *
 * @package    tool_database_cleaner
 * @category   test
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_tool_database_cleaner extends behat_base {

    /**
     * Turn a named activity instance into an orphan by deleting its activity
     * instance row directly (leaving the course_modules row behind). This
     * simulates the database corruption the tool is built to detect.
     *
     * @Given /^the "(?P<activityname_string>(?:[^"]|\\")*)" activity in "(?P<course_string>(?:[^"]|\\")*)" is an orphan$/
     *
     * @param string $activityname The activity name (as created by the generator).
     * @param string $course The course shortname / fullname / idnumber.
     */
    public function the_activity_in_course_is_an_orphan($activityname, $course) {
        global $DB;

        $courseid = $this->get_course_id($course);
        if ($courseid === null) {
            throw new \Exception('Could not find course "' . $course . '"');
        }

        $modinfo = get_fast_modinfo($courseid);
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->name === $activityname) {
                // Delete the activity instance row, leaving the course_modules
                // row behind as an orphan.
                $DB->delete_records($cm->modname, ['id' => $cm->instance]);
                return;
            }
        }

        throw new \Exception('Could not find activity "' . $activityname . '" in course "' . $course . '"');
    }
}
