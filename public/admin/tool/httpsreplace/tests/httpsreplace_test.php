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
 * HTTPS find and replace Tests
 *
 * @package   tool_httpsreplace
 * @copyright Copyright (c) 2016 Blackboard Inc. (http://www.blackboard.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_httpsreplace;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests the httpsreplace tool.
 *
 * @package   tool_httpsreplace
 * @covers    \tool_httpsreplace\url_finder
 * @copyright Copyright (c) 2016 Blackboard Inc. (http://www.blackboard.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class httpsreplace_test extends \advanced_testcase {

    /**
     * Data provider for test_upgrade_http_links
     */
    public static function upgrade_http_links_provider(): array {
        global $CFG;
        // Get the http url, since the default test wwwroot is https.
        $wwwroothttp = preg_replace('/^https:/', 'http:', $CFG->wwwroot);
        return [
            "Test image from another site should be replaced" => [
                "content" => '<img src="' . self::getExternalTestFileUrl('/test.jpg', false) . '">',
                "outputregex" => '/UPDATE/',
                "expectedcontent" => '<img src="' . self::get_converted_http_link('/test.jpg') . '">',
            ],
            "Test object from another site should be replaced" => [
                "content" => '<object data="' . self::getExternalTestFileUrl('/test.swf', false) . '">',
                "outputregex" => '/UPDATE/',
                "expectedcontent" => '<object data="' . self::get_converted_http_link('/test.swf') . '">',
            ],
            "Test image from a site with international name should be replaced" => [
                "content" => '<img src="http://中国互联网络信息中心.中国/logosy/201706/W01.png">',
                "outputregex" => '/UPDATE/',
                "expectedcontent" => '<img src="https://中国互联网络信息中心.中国/logosy/201706/W01.png">',
            ],
            "Link that is from this site should be replaced" => [
                "content" => '<img src="' . $wwwroothttp . '/logo.png">',
                "outputregex" => '/UPDATE/',
                "expectedcontent" => '<img src="' . $CFG->wwwroot . '/logo.png">',
            ],
            "Link that is from this site, https new so doesn't need replacing" => [
                "content" => '<img src="' . $CFG->wwwroot . '/logo.png">',
                "outputregex" => '/^$/',
                "expectedcontent" => '<img src="' . $CFG->wwwroot . '/logo.png">',
            ],
            "Unavailable image should be replaced" => [
                "content" => '<img src="http://intentionally.unavailable/link1.jpg">',
                "outputregex" => '/UPDATE/',
                "expectedcontent" => '<img src="https://intentionally.unavailable/link1.jpg">',
            ],
            "Https content that has an http url as a param should not be replaced" => [
                "content" => '<img src="https://anothersite.com?param=http://asdf.com">',
                "outputregex" => '/^$/',
                "expectedcontent" => '<img src="https://anothersite.com?param=http://asdf.com">',
            ],
            "Search for params should be case insensitive" => [
                "content" => '<object DATA="' . self::getExternalTestFileUrl('/test.swf', false) . '">',
                "outputregex" => '/UPDATE/',
                "expectedcontent" => '<object DATA="' . self::get_converted_http_link('/test.swf') . '">',
            ],
            "URL should be case insensitive" => [
                "content" => '<object data="HTTP://some.site/path?query">',
                "outputregex" => '/UPDATE/',
                "expectedcontent" => '<object data="https://some.site/path?query">',
            ],
            "More params should not interfere" => [
                "content" => '<img alt="A picture" src="' . self::getExternalTestFileUrl('/test.png', false) .
                    '" width="1”><p style="font-size: \'20px\'"></p>',
                "outputregex" => '/UPDATE/',
                "expectedcontent" => '<img alt="A picture" src="' . self::get_converted_http_link('/test.png') .
                    '" width="1”><p style="font-size: \'20px\'"></p>',
            ],
            "Broken URL should not be changed" => [
                "content" => '<img src="broken.' . self::getExternalTestFileUrl('/test.png', false) . '">',
                "outputregex" => '/^$/',
                "expectedcontent" => '<img src="broken.' . self::getExternalTestFileUrl('/test.png', false) . '">',
            ],
            "Link URL should not be changed" => [
                "content" => '<a href="' . self::getExternalTestFileUrl('/test.png', false) . '">' .
                    self::getExternalTestFileUrl('/test.png', false) . '</a>',
                "outputregex" => '/^$/',
                "expectedcontent" => '<a href="' . self::getExternalTestFileUrl('/test.png', false) . '">' .
                    self::getExternalTestFileUrl('/test.png', false) . '</a>',
            ],
            "Test image from another site should be replaced but link should not" => [
                "content" => '<a href="' . self::getExternalTestFileUrl('/test.png', false) . '"><img src="' .
                    self::getExternalTestFileUrl('/test.jpg', false) . '"></a>',
                "outputregex" => '/UPDATE/',
                "expectedcontent" => '<a href="' . self::getExternalTestFileUrl('/test.png', false) . '"><img src="' .
                    self::get_converted_http_link('/test.jpg') . '"></a>',
            ],
        ];
    }

    /**
     * Convert the HTTP external test file URL to use HTTPS.
     *
     * Note: We *must not* use getExternalTestFileUrl with the True option
     * here, becase it is reasonable to have only one of these set due to
     * issues with SSL certificates.
     *
     * @param   string  $path Path to be rewritten
     * @return  string
     */
    protected static function get_converted_http_link($path) {
        return preg_replace('/^http:/', 'https:', self::getExternalTestFileUrl($path, false));
    }

    /**
     * Test upgrade_http_links
     * @param string $content Example content that we'll attempt to replace.
     * @param string $outputregex Regex for what output we expect.
     * @param string $expectedcontent What content we are expecting afterwards.
     * @dataProvider upgrade_http_links_provider
     */
    public function test_upgrade_http_links($content, $outputregex, $expectedcontent): void {
        global $DB;

        $this->resetAfterTest();
        $this->expectOutputRegex($outputregex);

        // Mock the http client to return an (un)successful response.
        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(str_contains($content, '.unavailable') ? 404 : 200));

        $finder = new url_finder();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course((object) [
            'summary' => $content,
        ]);

        $finder->upgrade_http_links();

        $summary = $DB->get_field('course', 'summary', ['id' => $course->id]);
        $this->assertStringContainsString($expectedcontent, $summary);
        $this->assertCount(expectedCount: 0, haystack: $history);
    }

    /**
     * Data provider for test_http_link_stats
     */
    public static function http_link_stats_provider(): array {
        global $CFG;
        // Get the http url, since the default test wwwroot is https.
        $wwwrootdomain = 'www.example.com';
        $wwwroothttp = preg_replace('/^https:/', 'http:', $CFG->wwwroot);
        $testdomain = self::get_converted_http_link('');
        return [
            "Test image from an available site so shouldn't be reported" => [
                "content" => '<img src="' . self::getExternalTestFileUrl('/test.jpg', false) . '">',
                "domain" => $testdomain,
                "response" => new Response(200),
                "expectedcount" => 0,
                "expectedcurlcount" => 1,
            ],
            "Link that is from this site shouldn't be reported" => [
                "content" => '<img src="' . $wwwroothttp . '/logo.png">',
                "domain" => $wwwrootdomain,
                "response" => new Response(200),
                "expectedcount" => 0,
                "expectedcurlcount" => 1,
            ],
            "Unavailable, but https shouldn't be reported" => [
                "content" => '<img src="https://intentionally.unavailable/logo.png">',
                "domain" => 'intentionally.unavailable',
                "response" => new ConnectException(
                    "cURL error 6: Could not resolve host: intentionally.unavailable",
                    new Request('HEAD', 'https://intentionally.unavailable/')
                ),
                "expectedcount" => 0,
                "expectedcurlcount" => 0,
            ],
            "Unavailable image should be reported" => [
                "content" => '<img src="http://intentionally.unavailable/link1.jpg">',
                "domain" => 'intentionally.unavailable',
                "response" => new ConnectException(
                    "cURL error 6: Could not resolve host: intentionally.unavailable",
                    new Request('HEAD', 'https://intentionally.unavailable/')
                ),
                "expectedcount" => 1,
                "expectedcurlcount" => 1,
            ],
            "Unavailable object should be reported" => [
                "content" => '<object data="http://intentionally.unavailable/file.swf">',
                "domain" => 'intentionally.unavailable',
                "response" => new ConnectException(
                    "cURL error 6: Could not resolve host: intentionally.unavailable",
                    new Request('HEAD', 'https://intentionally.unavailable/')
                ),
                "expectedcount" => 1,
                "expectedcurlcount" => 1,
            ],
            "Link should not be reported" => [
                "content" => '<a href="http://intentionally.unavailable/page.php">Link</a>',
                "domain" => 'intentionally.unavailable',
                "response" => new ConnectException(
                    "cURL error 6: Could not resolve host: intentionally.unavailable",
                    new Request('HEAD', 'https://intentionally.unavailable/')
                ),
                "expectedcount" => 0,
                "expectedcurlcount" => 0,
            ],
            "Text should not be reported" => [
                "content" => 'http://intentionally.unavailable/page.php',
                "domain" => 'intentionally.unavailable',
                "response" => new ConnectException(
                    "cURL error 6: Could not resolve host: intentionally.unavailable",
                    new Request('HEAD', 'https://intentionally.unavailable/')
                ),
                "expectedcount" => 0,
                "expectedcurlcount" => 0,
            ],
        ];
    }

    /**
     * Test http_link_stats
     * @param string $content Example content that we'll attempt to replace.
     * @param string $domain The domain we will check was replaced.
     * @param Response|ConnectException $response The response or exception for the mocked http_client.
     * @param string $expectedcount Number of urls from that domain that we expect to be replaced.
     * @param string $expectedcurlcount Number of curl calls to check availability.
     * @dataProvider http_link_stats_provider
     */
    public function test_http_link_stats($content, $domain, $response, $expectedcount, $expectedcurlcount): void {
        $this->resetAfterTest();

        // Mock the http client to return a (un)successful response.
        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(str_contains($content, '.unavailable') ? 404 : 200));

        $finder = new url_finder();

        $generator = $this->getDataGenerator();
        $generator->create_course((object) [
            'summary' => $content,
        ]);

        $results = $finder->http_link_stats();

        $this->assertEquals($expectedcount, $results[$domain] ?? 0);
        $this->assertCount(expectedCount: $expectedcurlcount, haystack: $history);

    }

    /**
     * Test links and text are not changed
     */
    public function test_links_and_text(): void {
        global $DB;

        $this->resetAfterTest();
        $this->expectOutputRegex('/^$/');

        // Mock the http client to return an unsuccessful response.
        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(404));

        $finder = new url_finder();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course((object) [
            'summary' => '<a href="http://intentionally.unavailable/page.php">Link</a> http://other.unavailable/page.php',
        ]);

        $results = $finder->http_link_stats();
        $this->assertCount(0, $results);

        $finder->upgrade_http_links();

        $results = $finder->http_link_stats();
        $this->assertCount(0, $results);
        $this->assertCount(expectedCount: 0, haystack: $history);

        $summary = $DB->get_field('course', 'summary', ['id' => $course->id]);
        $this->assertStringContainsString('http://intentionally.unavailable/page.php', $summary);
        $this->assertStringContainsString('http://other.unavailable/page.php', $summary);
        $this->assertStringNotContainsString('https://intentionally.unavailable', $summary);
        $this->assertStringNotContainsString('https://other.unavailable', $summary);
    }

    /**
     * If we have an http wwwroot then we shouldn't report it.
     */
    public function test_httpwwwroot(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        $CFG->wwwroot = preg_replace('/^https:/', 'http:', $CFG->wwwroot);
        $this->expectOutputRegex('/^$/');

        // Mock the http client to return a successful response.
        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(200));

        $finder = new url_finder();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course((object) [
            'summary' => '<img src="' . $CFG->wwwroot . '/image.png">',
        ]);

        $results = $finder->http_link_stats();
        $this->assertCount(0, $results);
        $this->assertCount(expectedCount: 0, haystack: $history);

        $finder->upgrade_http_links();
        $summary = $DB->get_field('course', 'summary', ['id' => $course->id]);
        $this->assertStringContainsString($CFG->wwwroot, $summary);
    }

    /**
     * Test that links in excluded tables are not replaced
     */
    public function test_upgrade_http_links_excluded_tables(): void {
        $this->resetAfterTest();

        set_config('test_upgrade_http_links', '<img src="http://somesite/someimage.png" />');

        // Mock the http client to return a successful response.
        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(200));

        $finder = new url_finder();

        ob_start();
        $results = $finder->upgrade_http_links();
        $output = ob_get_contents();
        ob_end_clean();
        $this->assertTrue($results);
        $this->assertStringNotContainsString('https://somesite', $output);
        $testconf = get_config('core', 'test_upgrade_http_links');
        $this->assertStringContainsString('http://somesite', $testconf);
        $this->assertStringNotContainsString('https://somesite', $testconf);
        $this->assertCount(expectedCount: 0, haystack: $history);
    }

    /**
     * Test renamed domains
     */
    public function test_renames(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        $this->expectOutputRegex('/UPDATE/');

        $renames = [
            'example.com' => 'secure.example.com',
        ];

        set_config('renames', json_encode($renames), 'tool_httpsreplace');

        // Mock the http client to return a successful response.
        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(200));

        $finder = new url_finder();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course((object) [
            'summary' => '<script src="http://example.com/test.js"><img src="http://EXAMPLE.COM/someimage.png">',
        ]);

        $results = $finder->http_link_stats();
        $this->assertCount(0, $results);
        $this->assertCount(expectedCount: 1, haystack: $history);

        $finder->upgrade_http_links();

        $summary = $DB->get_field('course', 'summary', ['id' => $course->id]);
        $this->assertStringContainsString('https://secure.example.com', $summary);
        $this->assertStringNotContainsString('http://example.com', $summary);
        $this->assertEquals('<script src="https://secure.example.com/test.js">' .
            '<img src="https://secure.example.com/someimage.png">', $summary);
    }

    /**
     * When there are many different pieces of contents from the same site, we should only run replace once
     */
    public function test_multiple(): void {
        global $DB;
        $this->resetAfterTest();
        $original1 = '';
        $expected1 = '';
        $original2 = '';
        $expected2 = '';
        for ($i = 0; $i < 15; $i++) {
            $original1 .= '<img src="http://example.com/image' . $i . '.png">';
            $expected1 .= '<img src="https://example.com/image' . $i . '.png">';
            $original2 .= '<img src="http://example.com/image' . ($i + 15 ) . '.png">';
            $expected2 .= '<img src="https://example.com/image' . ($i + 15) . '.png">';
        }

        // Mock the http client to return a successful response.
        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(200));

        $finder = new url_finder();

        $generator = $this->getDataGenerator();
        $course1 = $generator->create_course((object) ['summary' => $original1]);
        $course2 = $generator->create_course((object) ['summary' => $original2]);

        ob_start();
        $finder->upgrade_http_links();
        $output = ob_get_contents();
        ob_end_clean();

        $this->assertCount(expectedCount: 0, haystack: $history);

        // Make sure everything is replaced.
        $summary1 = $DB->get_field('course', 'summary', ['id' => $course1->id]);
        $this->assertEquals($expected1, $summary1);
        $summary2 = $DB->get_field('course', 'summary', ['id' => $course2->id]);
        $this->assertEquals($expected2, $summary2);

        // Make sure only one UPDATE statment was called.
        $this->assertEquals(1, preg_match_all('/UPDATE/', $output));
    }

    /**
     * Test the tool when the column name is a reserved word in SQL (in this case 'where')
     */
    public function test_reserved_words(): void {
        global $DB;

        $this->resetAfterTest();
        $this->expectOutputRegex('/UPDATE/');

        // Create a table with a field that is a reserved SQL word.
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('reserved_words_temp');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('where', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $dbman->create_table($table);

        // Insert a record with an <img> in this table and run tool.
        $content = '<img src="http://example.com/image.png">';
        $expectedcontent = '<img src="https://example.com/image.png">';
        $columnamequoted = $dbman->generator->getEncQuoted('where');
        $DB->execute("INSERT INTO {reserved_words_temp} ($columnamequoted) VALUES (?)", [$content]);

        // Mock the http client to return a successful response.
        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(200));

        $finder = new url_finder();
        $finder->upgrade_http_links();

        $record = $DB->get_record('reserved_words_temp', []);
        $this->assertStringContainsString($expectedcontent, $record->where);
        $this->assertCount(expectedCount: 0, haystack: $history);

        $dbman->drop_table($table);
    }
}
