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

declare(strict_types=1);

namespace core_badges;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/badgeslib.php');

/**
 * Unit tests for backpack_api2p1_mapping class.
 *
 * @package     core_badges
 * @copyright   2026 Daniel Ziegenberg <daniel.ziegenberg@tuwien.ac.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(backpack_api2p1_mapping::class)]
final class backpack_api2p1_mapping_test extends \advanced_testcase {

    /**
     * Create a mapping with the given properties.
     *
     * @param string $action The action of this method.
     * @param string $url The base url of this backpack.
     * @param mixed $postparams The post parameters.
     * @param bool $multiple Whether the response is an array.
     * @param string $method The request method.
     * @param bool $json Whether the payload is json.
     * @param bool $authrequired Whether authorization is required.
     * @return backpack_api2p1_mapping
     */
    private function get_mapping(
        string $action,
        string $url,
        $postparams,
        bool $multiple,
        string $method,
        bool $json,
        bool $authrequired,
    ): backpack_api2p1_mapping {
        return new backpack_api2p1_mapping(
            $action,
            $url,
            $postparams,
            $multiple,
            $method,
            $json,
            $authrequired,
            false,
            OPEN_BADGES_V2P1,
        );
    }

    /**
     * Test that a POST assertion request is sent as json with a bearer token.
     */
    public function test_post_assertion_request(): void {
        $this->resetAfterTest();

        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'result' => [
                'type' => 'Assertion',
                'id' => 'https://example.org/assertions/1',
                'issuedOn' => '2025-01-01T00:00:00Z',
            ],
        ])));

        $mapping = $this->get_mapping(
            'post.assertions',
            '[URL]/assertions',
            '[PARAM]',
            false,
            'post',
            true,
            true,
        );

        $result = $mapping->request(
            'https://backpack.example.org/api/v2p1',
            'test-access-token',
            ['assertion' => ['type' => 'Assertion', 'badge' => ['name' => 'Test badge']]],
        );

        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('https://backpack.example.org/api/v2p1/assertions', (string) $request->getUri());
        $this->assertEquals('application/json', $request->getHeaderLine('Accept'));
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('Bearer test-access-token', $request->getHeaderLine('Authorization'));

        $postbody = json_decode((string) $request->getBody(), true);
        $this->assertEquals('Test badge', $postbody['assertion']['badge']['name']);

        // The response is parsed and unwrapped from the "result" property.
        $this->assertEquals('Assertion', $result->type);
        $this->assertEquals('https://example.org/assertions/1', $result->id);
    }

    /**
     * Test that a GET assertion request is sent without a request body.
     */
    public function test_get_assertion_request(): void {
        $this->resetAfterTest();

        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            [
                'type' => 'Assertion',
                'id' => 'https://example.org/assertions/1',
                'issuedOn' => '2025-01-01T00:00:00Z',
            ],
        ])));

        $mapping = $this->get_mapping(
            'get.assertions',
            '[URL]/assertions',
            '[PARAM]',
            false,
            'get',
            true,
            true,
        );

        $result = $mapping->request('https://backpack.example.org/api/v2p1', 'test-access-token');

        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('https://backpack.example.org/api/v2p1/assertions', (string) $request->getUri());
        $this->assertEquals('application/json', $request->getHeaderLine('Accept'));
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('Bearer test-access-token', $request->getHeaderLine('Authorization'));
        $this->assertEquals('', (string) $request->getBody());

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('https://example.org/assertions/1', $result[0]->id);
    }

    /**
     * Test that a failed connection results in a null response.
     */
    public function test_connection_failure_returns_null(): void {
        $this->resetAfterTest();

        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new ConnectException(
            'Connection refused',
            new Request('POST', 'https://backpack.example.org/api/v2p1/assertions'),
        ));

        $mapping = $this->get_mapping(
            'post.assertions',
            '[URL]/assertions',
            '[PARAM]',
            false,
            'post',
            true,
            true,
        );

        $result = $mapping->request('https://backpack.example.org/api/v2p1', 'test-access-token', ['assertion' => []]);

        $this->assertNull($result);
    }
}
