<?php
// This file is part of Moodle - https://moodle.org/
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

namespace assignfeedback_editpdf;

/**
 * Unit tests for pdf class.
 *
 * @package    assignfeedback_editpdf
 * @category   test
 * @copyright  2026 Jayce Birrell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_editpdf\pdf
 */
final class pdf_test extends \advanced_testcase {
    /**
     * Call the private run_gs method via reflection.
     *
     * @param string $command
     * @param string $filename
     * @param array $output
     * @return void
     */
    private function run_gs(string $command, string $filename = '', array &$output = []): void {
        $method = new \ReflectionMethod(pdf::class, 'run_gs');
        $args = [$command, $filename, &$output];
        $method->invokeArgs(null, $args);
    }

    /**
     * Test that run_gs throws a moodle_exception when the process exceeds the timeout.
     */
    public function test_run_gs_timeout(): void {
        $this->resetAfterTest();
        set_config('gs_timeout', 1, 'assignfeedback_editpdf');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Ghostscript timed out');
        $this->run_gs('sleep 10', '/tmp/test.pdf');
    }

    /**
     * Test that run_gs completes without exception when gs_timeout is disabled.
     */
    public function test_run_gs_no_timeout_when_disabled(): void {
        $this->resetAfterTest();
        set_config('gs_timeout', 0, 'assignfeedback_editpdf');

        $this->run_gs('true');
        // If we reach here without exception, the test passes.
        $this->assertTrue(true);
    }

    /**
     * Test that run_gs captures stdout output correctly.
     */
    public function test_run_gs_captures_stdout(): void {
        $this->resetAfterTest();
        set_config('gs_timeout', 0, 'assignfeedback_editpdf');

        $output = [];
        $this->run_gs('echo "line1"; echo "line2"', '', $output);
        $this->assertCount(2, $output);
        $this->assertSame('line1', $output[0]);
        $this->assertSame('line2', $output[1]);
    }

    /**
     * Test that run_gs throws an exception for a non-zero exit code.
     */
    public function test_run_gs_nonzero_exit_code(): void {
        $this->resetAfterTest();
        set_config('gs_timeout', 0, 'assignfeedback_editpdf');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Ghostscript failed');
        $this->run_gs('false', '/tmp/test.pdf');
    }

    /**
     * Test that run_gs handles a command that produces stderr without issues.
     */
    public function test_run_gs_stderr_does_not_block(): void {
        $this->resetAfterTest();
        set_config('gs_timeout', 5, 'assignfeedback_editpdf');

        $output = [];
        $this->run_gs('echo "stdout line" && echo "stderr line" >&2', '', $output);
        $this->assertCount(1, $output);
        $this->assertSame('stdout line', $output[0]);
    }
}
