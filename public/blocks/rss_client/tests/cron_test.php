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

namespace block_rss_client;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../../moodleblock.class.php');
require_once(__DIR__ . '/../block_rss_client.php');

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

/**
 * PHPunit tests for rss client cron.
 *
 * @package    block_rss_client
 * @copyright  2015 Universit of Nottingham
 * @author     Neill Magill <neill.magill@nottingham.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cron_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        \core\rss\reader::reset_cache();
    }

    /**
     * Build a \core\http_client backed by a Guzzle MockHandler serving the given responses.
     *
     * @param array $queue The queue of responses/exceptions to serve.
     * @return \core\http_client
     */
    private function get_mock_client(array $queue): \core\http_client {
        return new \core\http_client([
            'mock' => new MockHandler($queue),
            // SimplePie's Psr18Client follows redirects itself; Guzzle must not double-handle them.
            'allow_redirects' => false,
        ]);
    }

    /**
     * Get the contents of the shared rsstest.xml fixture.
     *
     * @return string
     */
    private function get_fixture(): string {
        global $CFG;
        return file_get_contents($CFG->dirroot . '/lib/tests/fixtures/rsstest.xml');
    }

    /**
     * Run the cron task, capturing any output, for the given task with the HTTP client injected.
     *
     * @param \block_rss_client\task\refreshfeeds $task The task to run.
     * @return string The captured output.
     */
    private function run_task(\block_rss_client\task\refreshfeeds $task): string {
        ob_start();
        $task->execute();
        return ob_get_clean();
    }

    /**
     * Test that when a record has a skipuntil time that is greater
     * than the current time the attempt is skipped.
     */
    public function test_skip(): void {
        global $DB;
        $this->resetAfterTest();
        // Create a RSS feed record with a skip until time set to the future.
        $record = (object) array(
            'userid' => 1,
            'title' => 'Skip test feed',
            'preferredtitle' => '',
            'description' => 'A feed to test the skip time.',
            'shared' => 0,
            'url' => 'http://example.com/rss',
            'skiptime' => 330,
            'skipuntil' => time() + 300,
        );
        $DB->insert_record('block_rss_client', $record);

        $task = new \block_rss_client\task\refreshfeeds();
        $cronoutput = $this->run_task($task);

        $this->assertStringContainsString('skipping until ' . userdate($record->skipuntil), $cronoutput);
        $this->assertStringContainsString('0 feeds refreshed (took ', $cronoutput);
    }

    /**
     * Test that a valid feed served from an offline mock client is refreshed successfully.
     */
    public function test_feed_refreshed(): void {
        global $DB;
        $this->resetAfterTest();
        // Create a RSS feed record which is due to be refreshed.
        $record = (object) [
            'userid' => 1,
            'title' => 'Refresh test feed',
            'preferredtitle' => '',
            'description' => 'A feed to test refreshing.',
            'shared' => 0,
            'url' => 'http://example.com/rsstest.xml',
            'skiptime' => 0,
            'skipuntil' => 0,
        ];
        $record->id = $DB->insert_record('block_rss_client', $record);

        $task = new \block_rss_client\task\refreshfeeds();
        $task->set_http_client($this->get_mock_client([
            new Response(200, ['Content-Type' => 'application/rss+xml'], $this->get_fixture()),
        ]));

        $cronoutput = $this->run_task($task);
        $this->assertStringContainsString('ok', $cronoutput);
        $this->assertStringContainsString('1 feeds refreshed (took ', $cronoutput);
        $this->assertStringNotContainsString('Error: could not load', $cronoutput);
    }

    /**
     * Data provider for skip time tests.
     *
     * @return  array
     */
    public static function skip_time_increase_provider(): array {
        return [
            'Never failed' => [
                'skiptime' => 0,
                'skipuntil' => 0,
                'newvalue' => MINSECS * 5,
            ],
            'Failed before' => [
                // This should just double the time.
                'skiptime' => 330,
                'skipuntil' => time(),
                'newvalue' => 660,
            ],
            'Near max' => [
                'skiptime' => \block_rss_client\task\refreshfeeds::CLIENT_MAX_SKIPTIME - 5,
                'skipuntil' => time(),
                'newvalue' => \block_rss_client\task\refreshfeeds::CLIENT_MAX_SKIPTIME,
            ],
        ];
    }

    /**
     * Test that when a feed has an error the skip time is increased correctly.
     *
     * @dataProvider    skip_time_increase_provider
     */
    public function test_error($skiptime, $skipuntil, $newvalue): void {
        global $DB;
        $this->resetAfterTest();

        // A record that has failed before.
        $record = (object) [
            'userid' => 1,
            'title' => 'Skip test feed',
            'preferredtitle' => '',
            'description' => 'A feed to test the skip time.',
            'shared' => 0,
            'url' => 'http://example.com/rss',
            'skiptime' => $skiptime,
            'skipuntil' => $skipuntil,
        ];
        $record->id = $DB->insert_record('block_rss_client', $record);

        // Run the scheduled task and have it fail.
        $task = $this->getMockBuilder(\block_rss_client\task\refreshfeeds::class)
            ->onlyMethods(['fetch_feed'])
            ->getMock();

        $piemock = $this->getMockBuilder(\core\rss\reader::class)
            ->onlyMethods(['error'])
            ->getMock();

        $piemock->method('error')
            ->willReturn(true);

        $task->method('fetch_feed')
            ->willReturn($piemock);

        // Run the cron and capture its output.
        $this->expectOutputRegex("/.*Error: could not load\/find the RSS feed - skipping for {$newvalue} seconds.*/");
        $task->execute();
    }

    /**
     * Test that a feed which fails to be retrieved offline (a 404 response)
     * is recorded as an error and the skip time is set.
     */
    public function test_failed_feed_recorded(): void {
        global $DB;
        $this->resetAfterTest();
        // Create a RSS feed record which has never failed and is due to be refreshed.
        $record = (object) [
            'userid' => 1,
            'title' => 'Failing test feed',
            'preferredtitle' => '',
            'description' => 'A feed that will fail to load.',
            'shared' => 0,
            'url' => 'http://example.com/rsstest-which-doesnt-exist.xml',
            'skiptime' => 0,
            'skipuntil' => 0,
        ];
        $record->id = $DB->insert_record('block_rss_client', $record);

        $task = new \block_rss_client\task\refreshfeeds();
        $task->set_http_client($this->get_mock_client([
            new Response(404),
        ]));

        $cronoutput = $this->run_task($task);
        $this->assertStringContainsString('Error: could not load/find the RSS feed - skipping for ' . MINSECS * 5 . ' seconds.', $cronoutput);
        $this->assertStringContainsString('0 feeds refreshed (took ', $cronoutput);

        // The failure state should now be stored in the database.
        $updated = $DB->get_record('block_rss_client', ['id' => $record->id]);
        $this->assertEquals(MINSECS * 5, $updated->skiptime);
        $now = time();
        $this->assertGreaterThan($now, $updated->skipuntil);
    }
}
