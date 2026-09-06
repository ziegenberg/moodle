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

use GuzzleHttp\Psr7\Response;

/**
 * Unit tests for the \core\http_client mocked HTTP responses.
 *
 * @package    core
 * @category   test
 * @copyright  2025 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \core\http_client
 */
final class http_client_test extends \advanced_testcase {
    protected function tearDown(): void {
        http_client::clear_mock_responses();
        parent::tearDown();
    }

    /**
     * A configured mocked response is served for a matching request.
     */
    public function test_mocked_response_is_served(): void {
        http_client::set_mock_responses([
            'https://example.com/feed.xml' => [
                new Response(200, ['Content-Type' => 'application/rss+xml'], '<rss version="2.0"/>'),
            ],
        ]);

        $client = new http_client(['http_errors' => false]);
        $response = $client->request('GET', 'https://example.com/feed.xml');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/rss+xml', $response->getHeaderLine('Content-Type'));
        $this->assertSame('<rss version="2.0"/>', (string) $response->getBody());
    }

    /**
     * Requests are matched on scheme, host and path, ignoring the query string.
     */
    public function test_mocked_response_ignores_query_string(): void {
        http_client::set_mock_responses([
            'https://example.com/feed.xml' => [
                new Response(200, [], 'content'),
            ],
        ]);

        $client = new http_client(['http_errors' => false]);
        $response = $client->request('GET', 'https://example.com/feed.xml?token=abc123');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('content', (string) $response->getBody());
    }

    /**
     * Responses for a URL are served in order, repeating the last one once exhausted.
     */
    public function test_mocked_responses_are_consumed_in_order_and_repeated(): void {
        http_client::set_mock_responses([
            'https://example.com/feed.xml' => [
                new Response(200, [], 'one'),
                new Response(500, [], 'two'),
            ],
        ]);

        $client = new http_client(['http_errors' => false]);

        $response = $client->request('GET', 'https://example.com/feed.xml');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('one', (string) $response->getBody());

        $response = $client->request('GET', 'https://example.com/feed.xml');
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('two', (string) $response->getBody());

        // The queue is exhausted; the last response is repeated.
        $response = $client->request('GET', 'https://example.com/feed.xml');
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('two', (string) $response->getBody());
    }

    /**
     * Mocked responses may also be provided as descriptor arrays (the format shared with Behat).
     */
    public function test_mocked_responses_accept_descriptor_arrays(): void {
        http_client::set_mock_responses([
            'https://example.com/feed.xml' => [
                ['status' => 200, 'headers' => ['Content-Type' => ['application/rss+xml']], 'body' => '<rss/>'],
            ],
        ]);

        $client = new http_client();
        $response = $client->request('GET', 'https://example.com/feed.xml');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/rss+xml', $response->getHeaderLine('Content-Type'));
        $this->assertSame('<rss/>', (string) $response->getBody());
    }

    /**
     * A request for a URL which has not been mocked receives a 404 rather than reaching the network.
     */
    public function test_unmocked_url_receives_404(): void {
        http_client::set_mock_responses([
            'https://example.com/feed.xml' => [
                new Response(200, [], 'content'),
            ],
        ]);

        $client = new http_client(['http_errors' => false]);

        $response = $client->request('GET', 'https://example.com/other.xml');
        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Mocked responses can be removed again.
     */
    public function test_clear_mock_responses(): void {
        http_client::set_mock_responses([
            'https://example.com/feed.xml' => [
                new Response(200),
            ],
        ]);
        $this->assertTrue(http_client::has_mock_responses());

        http_client::clear_mock_responses();
        $this->assertFalse(http_client::has_mock_responses());
    }
}
