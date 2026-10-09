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
 * Tests for the OAuth helper classes, verifying they use the core http_client instead of the deprecated curl class.
 *
 * @package    core
 * @category   test
 * @copyright  2026 Daniel Ziegenberg <daniel.ziegenberg@tuwien.ac.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core;

use core\oauth2\rest;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../oauthlib.php');

/**
 * A concrete implementation of the abstract \oauth2_client, for testing purposes.
 *
 * @package    core
 * @category   test
 */
class testable_oauth2_client extends \oauth2_client {

    /** @var bool Whether to use HTTP GET for token requests. */
    protected $httpget = false;

    /**
     * Set whether the client should use HTTP GET for token requests.
     *
     * @param bool $value
     */
    public function set_use_http_get(bool $value): void {
        $this->httpget = $value;
    }

    /**
     * Set a stored access token directly.
     *
     * @param \stdClass|null $accesstoken
     */
    public function set_accesstoken(?\stdClass $accesstoken): void {
        $this->accesstoken = $accesstoken;
    }

    /**
     * Set whether the client should use HTTP basic authentication.
     *
     * @param bool $value
     */
    public function set_basicauth(bool $value): void {
        $this->basicauth = $value;
    }

    /**
     * Returns the auth url for OAuth 2.0 request.
     *
     * @return string
     */
    protected function auth_url() {
        return 'https://example.test/auth';
    }

    /**
     * Returns the token url for OAuth 2.0 request.
     *
     * @return string
     */
    protected function token_url() {
        return 'https://example.test/token';
    }

    /**
     * Should HTTP GET be used instead of POST?
     *
     * @return bool
     */
    protected function use_http_get() {
        return $this->httpget;
    }
}

/**
 * A concrete implementation of the abstract \core\oauth2\rest, for testing purposes.
 *
 * @package    core
 * @category   test
 */
class testable_oauth2_rest extends rest {

    /** @var array The API functions to return from get_api_functions(). */
    private $apifunctions = [];

    /**
     * Constructor.
     *
     * @param testable_oauth2_client $oauthclient
     * @param array $apifunctions
     */
    public function __construct(testable_oauth2_client $oauthclient, array $apifunctions) {
        parent::__construct($oauthclient);
        $this->apifunctions = $apifunctions;
    }

    /**
     * Define the functions of the rest API.
     *
     * @return array
     */
    public function get_api_functions() {
        return $this->apifunctions;
    }
}

/**
 * Tests for the OAuth helper classes, verifying they use the core http_client.
 *
 * @package    core
 * @category   test
 * @copyright  2026 Daniel Ziegenberg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \oauth2_client
 * @covers \core\oauth2\rest
 */
final class oauthlib_test extends \advanced_testcase {

    /**
     * Create an http_client backed by a MockHandler, optionally capturing the request history.
     *
     * @param Response[] $responses The responses to return.
     * @param array|null $history If provided, the request history is written here.
     * @return http_client
     */
    private function get_test_http_client(array $responses, ?array &$history = null): http_client {
        $mock = new MockHandler($responses);
        $handlerstack = HandlerStack::create($mock);
        if ($history !== null) {
            $handlerstack->push(Middleware::history($history));
        }

        return new http_client(['handler' => $handlerstack]);
    }

    /**
     * Test that upgrade_token sends the correct POST request and stores the resulting token.
     *
     * @covers \oauth2_client::upgrade_token
     */
    public function test_upgrade_token_uses_post(): void {
        $this->resetAfterTest();

        $history = [];
        $httpclient = $this->get_test_http_client([
            new Response(200, [], json_encode(['access_token' => 'token123', 'expires_in' => 3600])),
        ], $history);

        $client = new testable_oauth2_client('clientid', 'clientsecret', new \moodle_url('/'), 'openid', $httpclient);
        $this->assertTrue($client->upgrade_token('abc'));

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('https://example.test/token', $request->getUri()->__toString());

        // The client id and secret are sent in the request body for non-basic-auth clients.
        parse_str($request->getBody()->getContents(), $postdata);
        $this->assertEquals('authorization_code', $postdata['grant_type']);
        $this->assertEquals('abc', $postdata['code']);
        $this->assertEquals('clientid', $postdata['client_id']);
        $this->assertEquals('clientsecret', $postdata['client_secret']);

        $this->assertFalse($request->hasHeader('Authorization'));

        // The access token must have been stored.
        $this->assertEquals('token123', $client->get_accesstoken()->token);
    }

    /**
     * Test that upgrade_token uses basic authentication when the client is configured to do so.
     *
     * @covers \oauth2_client::upgrade_token
     */
    public function test_upgrade_token_uses_basic_auth(): void {
        $this->resetAfterTest();

        $history = [];
        $httpclient = $this->get_test_http_client([
            new Response(200, [], json_encode(['access_token' => 'token123'])),
        ], $history);

        $client = new testable_oauth2_client('clientid', 'clientsecret', new \moodle_url('/'), 'openid', $httpclient);
        $client->set_basicauth(true);
        $this->assertTrue($client->upgrade_token('abc'));

        $request = $history[0]['request'];
        $this->assertEquals('Basic ' . base64_encode('clientid:clientsecret'), $request->getHeaderLine('Authorization'));

        // The client id and secret must not be sent in the body when using basic auth.
        parse_str($request->getBody()->getContents(), $postdata);
        $this->assertArrayNotHasKey('client_id', $postdata);
        $this->assertArrayNotHasKey('client_secret', $postdata);
    }

    /**
     * Test that upgrade_token uses GET with the parameters as a query string when requested.
     *
     * @covers \oauth2_client::upgrade_token
     */
    public function test_upgrade_token_uses_get(): void {
        $this->resetAfterTest();

        $history = [];
        $httpclient = $this->get_test_http_client([
            new Response(200, [], json_encode(['access_token' => 'token123'])),
        ], $history);

        $client = new testable_oauth2_client('clientid', 'clientsecret', new \moodle_url('/'), 'openid', $httpclient);
        $client->set_use_http_get(true);
        $this->assertTrue($client->upgrade_token('abc'));

        $request = $history[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        parse_str($request->getUri()->getQuery(), $querydata);
        $this->assertEquals('authorization_code', $querydata['grant_type']);
        $this->assertEquals('abc', $querydata['code']);
        $this->assertEquals('clientid', $querydata['client_id']);
    }

    /**
     * Test that upgrade_token throws a moodle_exception when the token endpoint returns an error.
     *
     * @covers \oauth2_client::upgrade_token
     */
    public function test_upgrade_token_throws_on_error_response(): void {
        $this->resetAfterTest();

        $httpclient = $this->get_test_http_client([
            new Response(400, [], 'The server rejected the request.'),
        ]);

        $client = new testable_oauth2_client('clientid', 'clientsecret', new \moodle_url('/'), 'openid', $httpclient);

        try {
            $client->upgrade_token('abc');
            $this->fail('Expected a moodle_exception to be thrown.');
        } catch (\moodle_exception $e) {
            $this->assertEquals('oauth2upgradetokenerror', $e->errorcode);
            $this->assertEquals('The server rejected the request.', $e->debuginfo);
        }
    }

    /**
     * Test that upgrade_token throws a moodle_exception when the response cannot be decoded.
     *
     * @covers \oauth2_client::upgrade_token
     */
    public function test_upgrade_token_throws_on_invalid_response(): void {
        $this->resetAfterTest();

        $httpclient = $this->get_test_http_client([
            new Response(200, [], 'not json'),
        ]);

        $client = new testable_oauth2_client('clientid', 'clientsecret', new \moodle_url('/'), 'openid', $httpclient);

        $this->expectException(\moodle_exception::class);
        $client->upgrade_token('abc');
    }

    /**
     * Test that get() adds the access token as a Bearer header for authenticated requests.
     *
     * @covers \oauth2_client::get
     */
    public function test_get_adds_bearer_header(): void {
        $this->resetAfterTest();

        $history = [];
        $httpclient = $this->get_test_http_client([
            new Response(200, [], '{"ok":true}'),
        ], $history);

        $client = new testable_oauth2_client('clientid', 'clientsecret', new \moodle_url('/'), 'openid', $httpclient);
        $client->set_accesstoken((object) ['token' => 'tok', 'scope' => 'openid']);

        $this->assertEquals('{"ok":true}', $client->get('https://example.test/resource'));

        $request = $history[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('Bearer tok', $request->getHeaderLine('Authorization'));
    }

    /**
     * Test that get() passes the access token as a query parameter when use_http_get() is enabled.
     *
     * @covers \oauth2_client::get
     */
    public function test_get_adds_access_token_query_parameter(): void {
        $this->resetAfterTest();

        $history = [];
        $httpclient = $this->get_test_http_client([
            new Response(200, [], '{"ok":true}'),
        ], $history);

        $client = new testable_oauth2_client('clientid', 'clientsecret', new \moodle_url('/'), 'openid', $httpclient);
        $client->set_use_http_get(true);
        $client->set_accesstoken((object) ['token' => 'tok', 'scope' => 'openid']);

        $client->get('https://example.test/resource');

        $request = $history[0]['request'];
        $this->assertFalse($request->hasHeader('Authorization'));
        parse_str($request->getUri()->getQuery(), $querydata);
        $this->assertEquals('tok', $querydata['access_token']);
    }

    /**
     * Test that get() merges any provided parameters into the query string.
     *
     * @covers \oauth2_client::get
     */
    public function test_get_merges_parameters(): void {
        $this->resetAfterTest();

        $history = [];
        $httpclient = $this->get_test_http_client([
            new Response(200, [], '{"ok":true}'),
        ], $history);

        $client = new testable_oauth2_client('clientid', 'clientsecret', new \moodle_url('/'), 'openid', $httpclient);
        $client->set_use_http_get(true);
        $client->set_accesstoken((object) ['token' => 'tok', 'scope' => 'openid']);

        $client->get('https://example.test/resource', ['a' => 'b']);

        $request = $history[0]['request'];
        parse_str($request->getUri()->getQuery(), $querydata);
        $this->assertEquals(['a' => 'b', 'access_token' => 'tok'], $querydata);
    }

    /**
     * Test that post() sends an array of parameters as form data.
     *
     * @covers \oauth2_client::post
     */
    public function test_post_sends_form_data(): void {
        $this->resetAfterTest();

        $history = [];
        $httpclient = $this->get_test_http_client([
            new Response(200, [], '{"ok":true}'),
        ], $history);

        $client = new testable_oauth2_client('clientid', 'clientsecret', new \moodle_url('/'), 'openid', $httpclient);
        $client->set_accesstoken((object) ['token' => 'tok', 'scope' => 'openid']);

        $this->assertEquals('{"ok":true}', $client->post('https://example.test/resource', ['foo' => 'bar']));

        $request = $history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        parse_str($request->getBody()->getContents(), $postdata);
        $this->assertEquals('bar', $postdata['foo']);
        $this->assertEquals('Bearer tok', $request->getHeaderLine('Authorization'));
    }

    /**
     * Test that download_one writes the response body directly to a file.
     *
     * @covers \oauth2_client::download_one
     */
    public function test_download_one_writes_to_file(): void {
        $this->resetAfterTest();

        $history = [];
        $httpclient = $this->get_test_http_client([
            new Response(200, [], 'filecontent'),
        ], $history);

        $client = new testable_oauth2_client('clientid', 'clientsecret', new \moodle_url('/'), 'openid', $httpclient);

        $tmpfile = tempnam(sys_get_temp_dir(), 'oauthlibtest_');
        $this->assertTrue($client->download_one('https://example.test/download', null, ['filepath' => $tmpfile]));
        $this->assertEquals('filecontent', file_get_contents($tmpfile));
        unlink($tmpfile);

        $request = $history[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('https://example.test/download', $request->getUri()->__toString());
    }

    /**
     * Test that download_one returns an error string and removes the partial file when the request fails.
     *
     * @covers \oauth2_client::download_one
     */
    public function test_download_one_removes_file_on_error(): void {
        $this->resetAfterTest();

        $httpclient = $this->get_test_http_client([
            new \GuzzleHttp\Exception\RequestException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('GET', 'https://example.test/download'),
            ),
        ]);

        $client = new testable_oauth2_client('clientid', 'clientsecret', new \moodle_url('/'), 'openid', $httpclient);

        $tmpfile = tempnam(sys_get_temp_dir(), 'oauthlibtest_');
        $result = $client->download_one('https://example.test/download', null, ['filepath' => $tmpfile]);
        $this->assertNotSame(true, $result);
        $this->assertIsString($result);
        $this->assertFileDoesNotExist($tmpfile);
    }

    /**
     * Test that a REST call for a JSON response type sends a properly authenticated GET request.
     *
     * @covers \core\oauth2\rest::call
     */
    public function test_rest_call_json(): void {
        $this->resetAfterTest();

        $history = [];
        $httpclient = $this->get_test_http_client([
            new Response(200, [], json_encode(['id' => 42])),
        ], $history);

        $oauthclient = new testable_oauth2_client('clientid', 'clientsecret', new \moodle_url('/'), 'openid', $httpclient);
        $oauthclient->set_accesstoken((object) ['token' => 'tok', 'scope' => 'openid']);

        $rest = new testable_oauth2_rest($oauthclient, [
            'list' => [
                'method' => 'get',
                'endpoint' => 'https://example.test/files',
                'args' => ['folder' => PARAM_PATH],
                'response' => 'json',
            ],
        ]);

        $result = $rest->call('list', ['folder' => '/']);
        $this->assertEquals(42, $result->id);

        $request = $history[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('Bearer tok', $request->getHeaderLine('Authorization'));
        $this->assertEquals('https://example.test/files?folder=%2F', $request->getUri()->__toString());
    }

    /**
     * Test that a REST call with a raw post body sends the body and any remaining arguments as query parameters.
     *
     * @covers \core\oauth2\rest::call
     */
    public function test_rest_call_raw_post(): void {
        $this->resetAfterTest();

        $history = [];
        $httpclient = $this->get_test_http_client([
            new Response(200, [], json_encode(['created' => true])),
        ], $history);

        $oauthclient = new testable_oauth2_client('clientid', 'clientsecret', new \moodle_url('/'), 'openid', $httpclient);
        $oauthclient->set_accesstoken((object) ['token' => 'tok', 'scope' => 'openid']);

        $rest = new testable_oauth2_rest($oauthclient, [
            'create' => [
                'method' => 'post',
                'endpoint' => 'https://example.test/files',
                'args' => ['uploadType' => PARAM_RAW],
                'response' => 'json',
            ],
        ]);

        $result = $rest->call('create', ['uploadType' => 'resumable'], json_encode(['name' => 'a.txt']));
        $this->assertTrue($result->created);

        $request = $history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('https://example.test/files?uploadType=resumable', $request->getUri()->__toString());
        $this->assertEquals(json_encode(['name' => 'a.txt']), $request->getBody()->getContents());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    /**
     * Test that a REST call for a headers response type returns the raw response headers.
     *
     * @covers \core\oauth2\rest::call
     */
    public function test_rest_call_headers(): void {
        $this->resetAfterTest();

        $history = [];
        $httpclient = $this->get_test_http_client([
            new Response(302, ['Location' => 'https://example.test/upload-session'], ''),
        ], $history);

        $oauthclient = new testable_oauth2_client('clientid', 'clientsecret', new \moodle_url('/'), 'openid', $httpclient);
        $oauthclient->set_accesstoken((object) ['token' => 'tok', 'scope' => 'openid']);

        $rest = new testable_oauth2_rest($oauthclient, [
            'upload' => [
                'method' => 'post',
                'endpoint' => 'https://example.test/files',
                'args' => ['uploadType' => PARAM_RAW],
                'response' => 'headers',
            ],
        ]);

        $headers = $rest->call('upload', ['uploadType' => 'resumable'], json_encode(['name' => 'a.txt']));
        $this->assertIsArray($headers);
        $this->assertContains('Location: https://example.test/upload-session', $headers);
    }
}
