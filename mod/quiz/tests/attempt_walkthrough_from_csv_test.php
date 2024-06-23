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

namespace mod_quiz;

use mod_quiz\test\attempt_walkthrough_from_csv_testcase;

/**
 * Quiz attempt walk through using data from csv file.
 *
 * @package    mod_quiz
 * @category   test
 * @copyright  2013 The Open University
 * @author     Jamie Pratt <me@jamiep.org>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_walkthrough_from_csv_test extends attempt_walkthrough_from_csv_testcase {

    /**
     * @var string[] names of the files which contain the test data.
     */
    protected static $files = ['questions', 'steps', 'results'];

    /**
     * Data provider method for test_walkthrough_from_csv. Called by PHPUnit.
     *
     * @return array One array element for each run of the test. Each element contains an array with the params for
     *                  test_walkthrough_from_csv.
     */
    public static function get_data_for_walkthrough(): array {
        $quizzes = self::load_csv_data_file('quizzes')['quizzes'];
        $datasets = [];
        foreach ($quizzes as $quizsettings) {
            $dataset = [];
            foreach (self::$files as $file) {
                if (file_exists(self::get_full_path_of_csv_file($file, $quizsettings['testnumber']))) {
                    $dataset[$file] = self::load_csv_data_file($file, $quizsettings['testnumber'])[$file];
                }
            }
            $datasets[] = [$quizsettings, $dataset];
        }
        return $datasets;
    }

    /**
     * The only test in this class. This is run multiple times depending on how many sets of files there are in fixtures/
     * directory.
     *
     * @param array $quizsettings of settings read from csv file quizzes.csv
     * @param array $csvdata of data read from csv file "questionsXX.csv", "stepsXX.csv" and "resultsXX.csv".
     * @dataProvider get_data_for_walkthrough
     */
    public function test_walkthrough_from_csv($quizsettings, $csvdata): void {

        // CSV data files for these tests were generated using :
        // https://github.com/jamiepratt/moodle-quiz-tools/tree/master/responsegenerator

        $this->create_quiz_simulate_attempts_and_check_results($quizsettings, $csvdata);
    }
}
