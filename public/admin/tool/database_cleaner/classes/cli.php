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
 * Command-line interface for the orphan course module cleaner.
 *
 * The actual entry-point script (cli/list_orphans.php) is a thin wrapper around
 * this class so the logic can be unit-tested by mocking $_SERVER['argv'] and
 * injecting an output stream.
 *
 * By default run() only reports orphans, grouped by bucket with counts. Pass
 * --fix to remove the deletable orphans inline. run() never calls exit(); it
 * returns an exit code so it can be exercised in tests.
 *
 * @package    tool_database_cleaner
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cli {

    /** @var resource The output stream. */
    private $stream;

    /**
     * Constructor.
     *
     * @param resource|null $stream Output stream (defaults to STDOUT).
     */
    public function __construct($stream = null) {
        if ($stream === null) {
            $stream = defined('STDOUT') ? STDOUT : fopen('php://output', 'w');
        }
        $this->stream = $stream;
    }

    /**
     * Run the CLI.
     *
     * Reads options from $_SERVER['argv'] via cli_get_params.
     *
     * @return int Exit code (0 success, 2 bad options).
     */
    public function run(): int {
        global $CFG;
        require_once($CFG->libdir . '/clilib.php');

        [$options, $unrecognized] = cli_get_params(['help' => false, 'fix' => false], ['h' => 'help']);

        if ($unrecognized) {
            $unrecognized = implode("\n  ", $unrecognized);
            $this->writeln(get_string('cliunknowoption', 'admin', $unrecognized));
            return 2;
        }

        if ($options['help']) {
            $this->print_help();
            return 0;
        }

        $cleaner = new cleaner();
        $result = $cleaner->find_orphans();

        $total = 0;
        foreach ($result as $bucket => $rows) {
            $count = count($rows);
            $total += $count;
            $this->writeln('');
            $this->writeln(str_repeat('=', 79));
            $this->writeln(get_string('bucket_' . $bucket, 'tool_database_cleaner') . ' (' . $count . ')');
            $this->writeln(str_repeat('=', 79));
            if ($count === 0) {
                $this->writeln('  ' . get_string('bucket_empty', 'tool_database_cleaner'));
                continue;
            }
            foreach ($rows as $row) {
                $this->writeln(sprintf(
                    '  cmid=%-8d course=%-8d section=%-8d module=%-12s instance=%-8d added=%s',
                    $row->id,
                    $row->course,
                    $row->section,
                    $row->modulename,
                    $row->instance,
                    $row->added ? userdate($row->added, get_string('strftimedatetime', 'langconfig')) : '-',
                ));
            }
        }

        $this->writeln('');
        $this->writeln(get_string('summary_total', 'tool_database_cleaner', $total));

        if (!$options['fix']) {
            // List only: no deletion.
            return 0;
        }

        $deletable = $result[cleaner::BUCKET_DELETABLE];
        if (empty($deletable)) {
            $this->writeln('');
            $this->writeln(get_string('fix_nothing_to_delete', 'tool_database_cleaner'));
            return 0;
        }

        $cmids = array_map(fn($row) => (int)$row->id, $deletable);
        $this->writeln('');
        $this->writeln(get_string('fix_starting', 'tool_database_cleaner', count($cmids)));

        $summary = $cleaner->delete_orphans($cmids);

        foreach ($summary->cleaned as $cmid) {
            $this->writeln('  ' . get_string('fix_cleaned', 'tool_database_cleaner', $cmid));
        }
        foreach ($summary->failed as $cmid => $message) {
            $this->writeln('  ' . get_string('fix_failed', 'tool_database_cleaner', ['id' => $cmid, 'error' => $message]));
        }

        $this->writeln('');
        $this->writeln(get_string('fix_summary', 'tool_database_cleaner', [
            'cleaned' => $summary->cleanedcount,
            'failed' => $summary->failedcount,
        ]));
        return 0;
    }

    /**
     * Write a line to the output stream.
     *
     * @param string $text
     */
    private function writeln(string $text): void {
        cli_write($text . PHP_EOL, $this->stream);
    }

    /**
     * Print the help text.
     */
    private function print_help(): void {
        // The heredoc keeps the help text readable; $sudo is escaped on purpose.
        $help = <<<EOF
Detect and report orphan course modules.

Lists every corrupt course module, grouped into three buckets:
  - deletable:      a true orphan (instance row missing) that can be safely removed.
  - cannot-verify:  the activity table is missing, so the orphan check cannot run.
  - cannot-clean:   a true orphan whose course or section is also missing.

This script performs NO deletion by default.

Options:
-h, --help  Print out this help
--fix       Remove the deletable orphans (inline, with per-orphan progress).
            Cannot-verify and cannot-clean orphans are never deleted.

Example:
\$sudo -u www-data /usr/bin/php admin/tool/database_cleaner/cli/list_orphans.php
\$sudo -u www-data /usr/bin/php admin/tool/database_cleaner/cli/list_orphans.php --fix
EOF;
        $this->writeln($help);
    }
}
