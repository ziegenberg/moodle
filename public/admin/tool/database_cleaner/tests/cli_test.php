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
 * Tests for the CLI (tool_database_cleaner\cli) via a mocked argv and an
 * in-memory output stream.
 *
 * @package    tool_database_cleaner
 * @category   test
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_database_cleaner\cli::class)]
final class cli_test extends \advanced_testcase {

    /**
     * Run the CLI with a mocked argv, capturing output on an in-memory stream.
     *
     * @param array $mockargv
     * @return array [int exitcode, string output]
     */
    private function run_cli(array $mockargv): array {
        $old = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = array_merge([''], $mockargv);

        $stream = fopen('php://memory', 'r+');
        $code = (new cli($stream))->run();
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        if ($old !== null) {
            $_SERVER['argv'] = $old;
        } else {
            unset($_SERVER['argv']);
        }
        return [$code, $output];
    }

    /**
     * A bare invocation reports the orphan but does not delete it.
     */
    public function test_bare_invocation_only_reports(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('coursebinenable', false, 'tool_recyclebin');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $assign = $generator->create_module('assign', ['course' => $course, 'section' => 1]);
        $DB->delete_records('assign', ['id' => $assign->id]);

        [$code, $output] = $this->run_cli([]);

        $this->assertSame(0, $code);
        // The orphan is reported.
        $this->assertStringContainsString('Deletable orphans (1)', $output);
        // A bare invocation performs no deletion.
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $assign->cmid]));
    }

    /**
     * A --fix invocation removes the deletable orphan and reports progress.
     */
    public function test_fix_invocation_deletes(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('coursebinenable', false, 'tool_recyclebin');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $assign = $generator->create_module('assign', ['course' => $course, 'section' => 1]);
        $DB->delete_records('assign', ['id' => $assign->id]);

        [$code, $output] = $this->run_cli(['--fix']);

        $this->assertSame(0, $code);
        // --fix deletes the deletable orphan.
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $assign->cmid]));
        // Per-orphan progress and summary are reported.
        $this->assertStringContainsString('Cleaned course module', $output);
        $this->assertStringContainsString('Cleaned: 1', $output);
        $this->assertStringContainsString('Failed: 0', $output);
    }
}
