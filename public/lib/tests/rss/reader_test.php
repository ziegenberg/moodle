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

namespace core\rss;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Unit tests for the Moodle RSS reader (\core\rss\reader) and its sanitizer.
 *
 * The feed is served from the committed rsstest.xml fixture through a Guzzle
 * MockHandler injected into \core\http_client, so these tests are fully offline
 * and deterministic - no network access is required.
 *
 * @package   core
 * @copyright 2009 Dan Poltawski <talktodan@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \core\rss\reader
 */
final class reader_test extends \advanced_testcase {

    // The number of seconds tests should wait for the server to respond (high to prevent false positives).

    protected function setUp(): void {
        parent::setUp();
        reader::reset_cache();
    }

    /**
     * Get the RSS feed fixture.
     *
     * @return string The contents of the rsstest.xml fixture.
     */
    private function fixture(): string {
        return file_get_contents(__DIR__ . '/../fixtures/rsstest.xml');
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
     * Convenience wrapper for the standard "valid feed" mock client.
     *
     * @return \core\http_client
     */
    private function get_valid_feed_client(): \core\http_client {
        return $this->get_mock_client([
            new Response(200, ['Content-Type' => 'application/rss+xml'], $this->fixture()),
        ]);
    }

    public function test_getfeed(): void {
        $feed = new reader(feedurl: 'http://example.com/rsstest.xml', client: $this->get_valid_feed_client());

        $this->assertInstanceOf(reader::class, $feed);

        $this->assertNull($feed->error(), "Failed to load the sample RSS file. %s");

        $this->assertSame('Moodle News', $feed->get_title());

        $this->assertSame('http://moodle.org/mod/forum/view.php?f=1', $feed->get_link());
        $this->assertSame("General news about Moodle.\n\nMoodle is a leading open-source course management system (CMS) - a software package designed to help educators create quality online courses. Such e-learning systems are sometimes also called Learning Management Systems (LMS) or Virtual Learning Environments (VLE). One of the main advantages of Moodle over other systems is a strong grounding in social constructionist pedagogy.",
            $feed->get_description());

        $this->assertSame('&amp;#169; 2007 moodle', $feed->get_copyright());
        $this->assertSame('http://moodle.org/pix/i/rsssitelogo.gif', $feed->get_image_url());
        $this->assertSame('moodle', $feed->get_image_title());
        $this->assertSame('http://moodle.org/', $feed->get_image_link());
        $this->assertEquals('140', $feed->get_image_width());
        $this->assertEquals('35', $feed->get_image_height());

        $this->assertNotEmpty($items = $feed->get_items());
        $this->assertCount(15, $items);

        $this->assertNotEmpty($itemone = $feed->get_item(0));

        $this->assertSame('Google HOP contest encourages pre-University students to work on Moodle', $itemone->get_title());
        $this->assertSame('http://moodle.org/mod/forum/discuss.php?d=85629', $itemone->get_link());
        $this->assertSame('http://moodle.org/mod/forum/discuss.php?d=85629', $itemone->get_id());
        $description = <<<EOD
by Martin Dougiamas. &nbsp;<p><p><img src="http://code.google.com/opensource/ghop/2007-8/images/ghoplogosm.jpg" align="right" style="margin:10px" />After their very successful <a href="http://code.google.com/soc/2007/">Summer of Code</a> program for University students, Google just announced their new <a href="http://code.google.com/opensource/ghop/2007-8/">Highly Open Participation contest</a>, designed to encourage pre-University students to get involved with open source projects via much smaller and diverse contributions.<br />
<br />
I'm very proud that Moodle has been selected as one of only <a href="http://code.google.com/opensource/ghop/2007-8/projects.html">ten open source projects</a> to take part in the inaugural year of this new contest.<br />
<br />
We have a <a href="http://code.google.com/p/google-highly-open-participation-moodle/issues/list">long list of small tasks</a> prepared already for students, but we would definitely like to see the Moodle community come up with more - so if you have any ideas for things you want to see done, please <a href="http://code.google.com/p/google-highly-open-participation-moodle/">send them to us</a>!  Just remember they can't take more than five days.<br />
<br />
Google will pay students US$100 for every three tasks they successfully complete, plus send a cool T-shirt.  There are also grand prizes including an all-expenses-paid trip to Google HQ in Mountain View, California.  If you are (or know) a young student with an interest in Moodle then give it a go! <br />
<br />
You can find out all the details on the <a href="http://code.google.com/p/google-highly-open-participation-moodle/">Moodle/GHOP contest site</a>.</p></p>
EOD;
        $description = purify_html($description);
        $this->assertSame($description, $itemone->get_description());

        // get_date('U') returns the raw parsed Unix timestamp. The fixture's pubDate
        // carries an explicit +0000 offset, so this is independent of the PHP/Moodle
        // timezone configuration (e.g. $CFG->timezone).
        $this->assertSame(strtotime('Fri, 30 Nov 2007 08:47:33 +0000'), $itemone->get_date('U'));

        // Last item.
        $this->assertNotEmpty($feed->get_item(14));
        // Past last item.
        $this->assertEmpty($feed->get_item(15));
    }

    /**
     * Test retrieving a url which doesn't exist (a 404 response).
     */
    public function test_failurl(): void {
        $feed = new reader(feedurl: 'http://example.com/rsstest-which-doesnt-exist.xml', client: $this->get_mock_client([
            new Response(404),
        ]));

        $this->assertNotEmpty($feed->error());
    }

    /**
     * Test retrieving a url which returns a server error (a 500 response).
     */
    public function test_fail500(): void {
        $feed = new reader(feedurl: 'http://example.com/rsstest-fails.xml', client: $this->get_mock_client([
            new Response(500),
        ]));

        $this->assertNotEmpty($feed->error());
        $this->assertEmpty($feed->get_title());
    }

    /**
     * Test that a failed connection (e.g. broken proxy or unreachable host)
     * results in an error on the feed.
     */
    public function test_failconnection(): void {
        $request = new Request('GET', 'http://example.com/rsstest.xml');

        $feed = new reader(feedurl: 'http://example.com/rsstest.xml', client: $this->get_mock_client([
            new ConnectException('Connection refused', $request),
        ]));

        $this->assertNotEmpty($feed->error());
        $this->assertEmpty($feed->get_title());
    }

    /**
     * Test retrieving a url which sends a redirect to another valid feed.
     *
     * SimplePie's PSR-18 client follows the redirect itself and records the
     * permanent URL for a 301 response.
     */
    public function test_redirect(): void {
        $feedurl = 'http://example.com/rss_redir.php';
        $redirecturl = 'http://example.com/rsstest.xml';

        $feed = new reader(feedurl: $feedurl, client: $this->get_mock_client([
            new Response(301, ['Location' => $redirecturl]),
            new Response(200, ['Content-Type' => 'application/rss+xml'], $this->fixture()),
        ]));

        $this->assertNull($feed->error());
        $this->assertSame('Moodle News', $feed->get_title());
        $this->assertSame('http://moodle.org/mod/forum/view.php?f=1', $feed->get_link());
        // A permanent (301) redirect is tracked by SimplePie.
        $this->assertSame($redirecturl, $feed->permanent_url);
    }

    /**
     * Data provider for content-type sniffing tests.
     *
     * @return array
     */
    public static function contenttype_provider(): array {
        return [
            'HTML page' => ['text/html'],
            'Generic XML' => ['application/xml'],
            'Plain text XML' => ['text/xml'],
        ];
    }

    /**
     * Test that feeds served with an unusual content-type are still recognised,
     * because SimplePie sniffs the content itself.
     *
     * @dataProvider contenttype_provider
     * @param string $contenttype The Content-Type header served with the feed.
     */
    public function test_contenttype_sniffing(string $contenttype): void {
        $feed = new reader(feedurl: 'http://example.com/feed', client: $this->get_mock_client([
            new Response(200, ['Content-Type' => $contenttype], $this->fixture()),
        ]));

        $this->assertNull($feed->error(), "Failed to parse feed served as {$contenttype}");
        $this->assertSame('Moodle News', $feed->get_title());
    }

    /**
     * Test that a feed response marked as gzip-compressed is parsed correctly.
     *
     * On the wire, the Guzzle transport transparently decompresses gzip-encoded
     * responses before they reach SimplePie; this test serves the already-decoded
     * body (as the transport would hand it on) together with the gzip header.
     */
    public function test_gzip_content_encoding(): void {
        $feed = new reader(feedurl: 'http://example.com/gzipfeed', client: $this->get_mock_client([
            new Response(200, [
                'Content-Type' => 'application/rss+xml',
                'Content-Encoding' => 'gzip',
            ], $this->fixture()),
        ]));

        $this->assertNull($feed->error());
        $this->assertSame('Moodle News', $feed->get_title());
    }

    /**
     * Test that a feed containing malicious HTML is cleaned through Moodle's
     * HTMLPurifier pipeline.
     *
     * @covers \core\rss\reader_sanitize
     */
    public function test_malicious_html_sanitised(): void {
        $evil = '<div onclick="alert(\'xss\')" style="display:none">'
            . '<script>alert(1)</script>'
            . '<a href="javascript:alert(2)">link</a>'
            . '<img src="x" onerror="alert(3)">'
            . '</div>';

        $feedxml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>'
            . '<title>Malicious feed</title>'
            . '<item><title>Click me</title>'
            . '<link>http://example.com/click</link>'
            . '<description><![CDATA[' . $evil . ']]></description>'
            . '</item></channel></rss>';

        $feed = new reader(feedurl: 'http://example.com/malicious', client: $this->get_mock_client([
            new Response(200, ['Content-Type' => 'application/rss+xml'], $feedxml),
        ]));

        $this->assertNull($feed->error());
        $item = $feed->get_item(0)->get_description();

        // The description is passed through the Moodle reader sanitizer (HTMLPurifier).
        $sanitizer = new reader_sanitize();
        $this->assertSame($sanitizer->sanitize($evil, \SimplePie\SimplePie::CONSTRUCT_HTML), $item);
        // No scripts or event handlers survive.
        $this->assertStringNotContainsString('<script', $item);
        $this->assertStringNotContainsString('onclick', $item);
        $this->assertStringNotContainsString('onerror', $item);
        $this->assertStringNotContainsString('javascript:', strtolower($item));
    }
}
