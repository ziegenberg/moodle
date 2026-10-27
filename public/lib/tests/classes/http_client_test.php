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

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use core\test\http_mock;

/**
 * Unit tests for the \core\http_client.
 *
 * @package    core
 * @category   test
 * @copyright  2025 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \core\http_client
 */
final class http_client_test extends \advanced_testcase {
    protected function tearDown(): void {
        http_mock::clear_responses();
        parent::tearDown();
    }

    /**
     * A mock handler passed through the 'mock' constructor option is used.
     */
    public function test_mock_handler_from_constructor_is_used(): void {
        $client = new http_client([
            'mock' => new MockHandler([
                new Response(200, [], 'from handler'),
            ]),
        ]);

        $response = $client->request('GET', 'https://example.com/feed.xml');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('from handler', (string) $response->getBody());
    }

    /**
     * Mocked responses installed via \core\test\http_mock take precedence over the constructor
     * mock handler.
     */
    public function test_http_mock_responses_take_precedence(): void {
        http_mock::set_responses([
            'https://example.com/feed.xml' => [
                new Response(200, [], 'from http_mock'),
            ],
        ]);

        $client = new http_client([
            'mock' => new MockHandler([
                new Response(200, [], 'from handler'),
            ]),
        ]);

        $response = $client->request('GET', 'https://example.com/feed.xml');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('from http_mock', (string) $response->getBody());
    }
}
