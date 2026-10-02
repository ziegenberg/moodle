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
require_once(__DIR__ . '/../classes/backpack_api.php');

/**
 * Unit tests for backpack_api_mapping class.
 *
 * @package     core_badges
 * @copyright   2026 Daniel Ziegenberg <daniel.ziegenberg@tuwien.ac.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(backpack_api_mapping::class)]
final class backpack_api_mapping_test extends \advanced_testcase {

    /**
     * Create a mapping for a user backpack with the given properties.
     *
     * @param string $action The action of this method.
     * @param string $url The base url of this backpack.
     * @param mixed $postparams The post parameters.
     * @param string $requestexporter The request exporter.
     * @param string $responseexporter The response exporter.
     * @param bool $multiple Whether the response is an array.
     * @param string $method The request method.
     * @param bool $json Whether the payload is json.
     * @param bool $authrequired Whether authorization is required.
     * @return backpack_api_mapping
     */
    private function get_user_mapping(
        string $action,
        string $url,
        $postparams,
        string $requestexporter,
        string $responseexporter,
        bool $multiple,
        string $method,
        bool $json,
        bool $authrequired,
    ): backpack_api_mapping {
        return new backpack_api_mapping(
            $action,
            $url,
            $postparams,
            $requestexporter,
            $responseexporter,
            $multiple,
            $method,
            $json,
            $authrequired,
            true,
            OPEN_BADGES_V2,
        );
    }

    /**
     * Test a GET request that requires authorization.
     *
     * The "collections" action fetches a list of badge collections, sending the
     * access token stored in the session as a bearer token.
     */
    public function test_authorized_get_request(): void {
        global $SESSION;

        $this->resetAfterTest();

        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'result' => [
                [
                    'entityType' => 'BackpackCollection',
                    'entityId' => 'collection-1',
                    'name' => 'Collection 1',
                    'description' => 'A test collection',
                    'share_url' => 'https://example.org/collection-1',
                    'published' => true,
                    'assertions' => [],
                ],
            ],
        ])));

        $mapping = $this->get_user_mapping(
            'collections',
            '[URL]/backpack/collections',
            [],
            '',
            'core_badges\external\collection_exporter',
            true,
            'get',
            true,
            true,
        );

        // Store an access token for the user backpack in the session.
        $SESSION->badges_user_backpack_access_token = 'test-access-token';

        $result = $mapping->request('https://backpack.example.org/api/v2', null, null, 'a@example.com', 'secret', null, 7);

        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('https://backpack.example.org/api/v2/backpack/collections', (string) $request->getUri());
        $this->assertEquals('application/json', $request->getHeaderLine('Accept'));
        $this->assertEquals('Bearer test-access-token', $request->getHeaderLine('Authorization'));
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));

        // The response is parsed and unwrapped from the "result" property, then exported.
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('Collection 1', $result[0]->name);
    }

    /**
     * Test an authorization request posting the username and password as form data.
     *
     * The "user" action exchanges the email and password for access and refresh
     * tokens, which are stored in the session.
     */
    public function test_oauth_token_request_uses_form_params(): void {
        global $SESSION;

        $this->resetAfterTest();

        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
        ])));

        $mapping = $this->get_user_mapping(
            'user',
            '[SCHEME]://[HOST]/o/token',
            ['username' => '[EMAIL]', 'password' => '[PASSWORD]'],
            '',
            'oauth_token_response',
            false,
            'post',
            false,
            false,
        );

        $result = $mapping->request('https://backpack.example.org/api/v2', null, null, 'a@example.com', 'secret', null, 7);

        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('https://backpack.example.org/o/token', (string) $request->getUri());
        $this->assertEquals('application/json', $request->getHeaderLine('Accept'));
        $this->assertEquals('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('username=a%40example.com&password=secret', (string) $request->getBody());

        // A successful login is signalled by returning -1.
        $this->assertEquals(-1, $result);

        // The tokens were stored in the session.
        $this->assertEquals('new-access-token', $SESSION->badges_user_backpack_access_token);
        $this->assertEquals('new-refresh-token', $SESSION->badges_user_backpack_refresh_token);
    }

    /**
     * Test a json encoded POST request that requires authorization.
     *
     * The "importbadge" action pushes a badge to the backpack and returns the
     * created assertion.
     */
    public function test_authorized_post_json_request(): void {
        $this->resetAfterTest();

        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            [
                'entityType' => 'Assertion',
                'entityId' => 'https://example.org/assertions/1',
                'issuedOn' => '2025-01-01T00:00:00Z',
            ],
        ])));

        $mapping = $this->get_user_mapping(
            'importbadge',
            '[URL]/backpack/import',
            ['url' => '[PARAM]'],
            '',
            'core_badges\external\assertion_exporter',
            false,
            'post',
            true,
            true,
        );

        $result = $mapping->request('https://backpack.example.org/api/v2', null, null, 'a@example.com', 'secret', 'https://example.org/badge/1', 7);

        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('https://backpack.example.org/api/v2/backpack/import', (string) $request->getUri());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals(['url' => 'https://example.org/badge/1'], json_decode((string) $request->getBody(), true));

        // The response assertion is exported.
        $this->assertEquals('Assertion', $result->type);
        $this->assertEquals('https://example.org/assertions/1', $result->id);
    }

    /**
     * Test a PUT request that requires authorization.
     *
     * The "updateassertion" action updates an existing assertion in the backpack.
     */
    public function test_authorized_put_request(): void {
        $this->resetAfterTest();

        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            [
                'entityType' => 'Assertion',
                'entityId' => 'https://example.org/assertions/1',
                'issuedOn' => '2025-01-01T00:00:00Z',
            ],
        ])));

        $mapping = new backpack_api_mapping(
            'updateassertion',
            '[URL]/assertions/[PARAM2]?expand=badgeclass&expand=issuer',
            '[PARAM]',
            'core_badges\external\assertion_exporter',
            'core_badges\external\assertion_exporter',
            false,
            'put',
            true,
            true,
            false,
            OPEN_BADGES_V2,
        );

        $result = $mapping->request(
            'https://backpack.example.org/api/v2',
            null,
            'https://example.org/assertions/1',
            'a@example.com',
            'secret',
            [
                'type' => 'Assertion',
                'id' => 'https://example.org/assertions/1',
                'issuedOn' => '2025-01-01T00:00:00Z',
                '@context' => 'https://w3id.org/openbadges/v2',
            ],
            7,
        );

        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $this->assertEquals('PUT', $request->getMethod());
        $this->assertEquals(
            'https://backpack.example.org/api/v2/assertions/https://example.org/assertions/1?expand=badgeclass&expand=issuer',
            (string) $request->getUri(),
        );
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));

        $postbody = json_decode((string) $request->getBody(), true);
        $this->assertEquals('Assertion', $postbody['type']);
        $this->assertEquals('https://example.org/assertions/1', $postbody['id']);

        $this->assertEquals('Assertion', $result->type);
    }

    /**
     * Test that an unsuccessful response is reported as an error and null is returned.
     */
    public function test_unsuccessful_response_returns_error(): void {
        $this->resetAfterTest();

        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'status' => [
                'success' => false,
                'description' => 'The badge could not be created.',
            ],
        ])));

        $mapping = $this->get_user_mapping(
            'importbadge',
            '[URL]/backpack/import',
            ['url' => '[PARAM]'],
            '',
            'core_badges\external\assertion_exporter',
            false,
            'post',
            true,
            true,
        );

        $result = $mapping->request('https://backpack.example.org/api/v2', null, null, 'a@example.com', 'secret', 'https://example.org/badge/1', 7);

        $this->assertNull($result);
        $this->assertEquals(['The badge could not be created.'], $mapping->get_errors());
    }

    /**
     * Test that a non-json response is reported as an error and null is returned.
     */
    public function test_invalid_json_response_returns_error(): void {
        $this->resetAfterTest();

        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new Response(200, [], '<html>not json</html>'));

        $mapping = $this->get_user_mapping(
            'collections',
            '[URL]/backpack/collections',
            [],
            '',
            'core_badges\external\collection_exporter',
            true,
            'get',
            true,
            true,
        );

        $result = $mapping->request('https://backpack.example.org/api/v2', null, null, 'a@example.com', 'secret', null, 7);

        $this->assertNull($result);
        $this->assertEquals([get_string('invalidrequest', 'error')], $mapping->get_errors());
    }

    /**
     * Test that a failed connection is reported as an error and null is returned.
     */
    public function test_connection_failure_returns_error(): void {
        $this->resetAfterTest();

        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $mock->append(new ConnectException(
            'Connection refused',
            new Request('POST', 'https://backpack.example.org/o/token'),
        ));

        $mapping = $this->get_user_mapping(
            'user',
            '[SCHEME]://[HOST]/o/token',
            ['username' => '[EMAIL]', 'password' => '[PASSWORD]'],
            '',
            'oauth_token_response',
            false,
            'post',
            false,
            false,
        );

        $result = $mapping->request('https://backpack.example.org/api/v2', null, null, 'a@example.com', 'secret', null, 7);

        $this->assertNull($result);
        $this->assertEquals([get_string('invalidrequest', 'error')], $mapping->get_errors());
    }
}
