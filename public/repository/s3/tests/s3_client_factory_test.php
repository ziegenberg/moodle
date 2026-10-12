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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace repository_s3;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler as GuzzleMock;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Tests for the S3 client factory.
 *
 * @package    repository_s3
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class s3_client_factory_test extends \basic_testcase {

    /**
     * Test that the client is created with the configured credentials and region.
     */
    public function test_create_client(): void {
        $client = s3_client_factory::create_client('accesskey', 'secretkey', 'eu-central-1');

        $this->assertInstanceOf(\Aws\S3\S3Client::class, $client);
        $this->assertEquals('eu-central-1', $client->getConfig('signing_region'));
        $credentials = $client->getCredentials()->wait();
        $this->assertEquals('accesskey', $credentials->getAccessKeyId());
        $this->assertEquals('secretkey', $credentials->getSecretKey());
    }

    /**
     * Test that the client parameters include the custom endpoint and path-style addressing.
     */
    public function test_build_params_with_custom_endpoint(): void {
        $params = s3_client_factory::build_params('accesskey', 'secretkey', 'us-east-1', 's3.example.com', true);

        $this->assertEquals('https://s3.example.com', $params['endpoint']);
        $this->assertTrue($params['use_path_style_endpoint']);
    }

    /**
     * Test that the client parameters do not include endpoint options when no endpoint is set.
     */
    public function test_build_params_without_endpoint(): void {
        $params = s3_client_factory::build_params('accesskey', 'secretkey', 'us-east-1');

        $this->assertArrayNotHasKey('endpoint', $params);
        $this->assertArrayNotHasKey('use_path_style_endpoint', $params);
    }

    /**
     * Test that endpoint URLs without a scheme are normalised.
     *
     * @dataProvider normalise_endpoint_provider
     * @param string $endpoint The endpoint URL.
     * @param string $expected The expected normalised URL.
     */
    public function test_normalise_endpoint(string $endpoint, string $expected): void {
        $this->assertEquals($expected, s3_client_factory::normalise_endpoint($endpoint));
    }

    /**
     * Data provider for test_normalise_endpoint.
     *
     * @return array
     */
    public static function normalise_endpoint_provider(): array {
        return [
            'no scheme' => ['s3.example.com', 'https://s3.example.com'],
            'https' => ['https://s3.example.com', 'https://s3.example.com'],
            'http' => ['http://s3.example.com:9000', 'http://s3.example.com:9000'],
        ];
    }

    /**
     * Test that requests are sent through the Moodle HTTP handler and do not follow redirects.
     */
    public function test_build_params_uses_moodle_http_handler(): void {
        $params = s3_client_factory::build_params('accesskey', 'secretkey', 'us-east-1');

        $this->assertArrayHasKey('http_handler', $params);
        $this->assertInstanceOf(\GuzzleHttp\HandlerStack::class, $params['http_handler']);
        $this->assertFalse($params['http']['allow_redirects']);
        $this->assertStringContainsString("Name: 'moodle_check_initial_request'", (string) $params['http_handler']);
        $this->assertStringContainsString("Name: 'moodle_allow_redirect'", (string) $params['http_handler']);
    }

    /**
     * Test that the Moodle response cache is enabled when requested.
     */
    public function test_build_params_with_cache(): void {
        $params = s3_client_factory::build_params('accesskey', 'secretkey', 'us-east-1', '', '', [
            'cache' => true,
            'module_cache' => 'repository',
        ]);
        $this->assertStringContainsString("Name: 'cache'", (string) $params['http_handler']);

        $params = s3_client_factory::build_params('accesskey', 'secretkey', 'us-east-1', '', '', [
            'cache' => false,
        ]);
        $this->assertStringNotContainsString("Name: 'cache'", (string) $params['http_handler']);
    }

    /**
     * Test that the Moodle proxy and proxy bypass configuration is passed to the HTTP handler.
     */
    public function test_build_params_with_proxy(): void {
        global $CFG;

        $old = $this->store_config(['proxyhost', 'proxyport', 'proxyuser', 'proxypassword', 'proxytype', 'proxybypass']);
        $CFG->proxyhost = 'proxy.example.com';
        $CFG->proxyport = 3128;
        $CFG->proxybypass = 's3.example.com, localhost';
        unset($CFG->proxyuser, $CFG->proxypassword);
        $CFG->proxytype = 'HTTP';

        $params = s3_client_factory::build_params('accesskey', 'secretkey', 'us-east-1');

        $this->assertEquals([
            'http' => 'http://proxy.example.com:3128',
            'https' => 'http://proxy.example.com:3128',
            'no' => ['s3.example.com', 'localhost'],
        ], $params['http']['proxy']);

        $this->restore_config($old);
    }

    /**
     * Test that a SOCKS5 proxy is used when configured.
     */
    public function test_build_params_with_socks_proxy(): void {
        global $CFG;

        $old = $this->store_config(['proxyhost', 'proxyport', 'proxyuser', 'proxypassword', 'proxytype', 'proxybypass']);
        $CFG->proxyhost = 'proxy.example.com';
        $CFG->proxyport = 1080;
        unset($CFG->proxyuser, $CFG->proxypassword, $CFG->proxybypass);
        $CFG->proxytype = 'SOCKS5';

        $params = s3_client_factory::build_params('accesskey', 'secretkey', 'us-east-1');

        $this->assertEquals('socks5://proxy.example.com:1080', $params['http']['proxy']['http']);
        $this->assertEquals('socks5://proxy.example.com:1080', $params['http']['proxy']['https']);

        $this->restore_config($old);
    }

    /**
     * Test that the security helper blocks requests to blocked hosts.
     */
    public function test_security_helper_blocks_requests(): void {
        global $CFG;

        $old = $this->store_config(['curlsecurityblockedhosts']);
        try {
            $CFG->curlsecurityblockedhosts = "blocked.example.com\n";

            $mock = new GuzzleMock([new Response(200, [], 'ok')]);
            $params = s3_client_factory::build_params('accesskey', 'secretkey', 'us-east-1', '', '', ['mock' => $mock]);
            $stack = $params['http_handler'];

            ob_start();
            try {
                $stack(new Request('GET', 'https://blocked.example.com/'), [])->wait();
                $this->fail('Expected RequestException');
            } catch (RequestException $e) {
                $this->assertStringContainsString('blocked', strtolower($e->getMessage()));
            } finally {
                ob_end_clean();
            }
        } finally {
            $this->restore_config($old);
        }
    }

    /**
     * Test that the security helper can be bypassed with the ignoresecurity setting.
     */
    public function test_security_helper_can_be_ignored(): void {
        global $CFG;

        $old = $this->store_config(['curlsecurityblockedhosts']);
        try {
            $CFG->curlsecurityblockedhosts = "blocked.example.com\n";

            $mock = new GuzzleMock([new Response(200, [], 'ok')]);
            $params = s3_client_factory::build_params('accesskey', 'secretkey', 'us-east-1', '', '', [
                'mock' => $mock,
                'ignoresecurity' => true,
            ]);
            $stack = $params['http_handler'];

            $response = $stack(new Request('GET', 'https://blocked.example.com/'), [])->wait();
            $this->assertEquals(200, $response->getStatusCode());
        } finally {
            $this->restore_config($old);
        }
    }

    /**
     * Test that requests to a custom endpoint use path-style addressing when enabled.
     */
    public function test_custom_endpoint_request_uses_path_style(): void {
        $history = [];
        $params = s3_client_factory::build_params('accesskey', 'secretkey', 'us-east-1', 'https://s3.example.com', true);
        $params['http_handler'] = $this->build_handler_stack($history, [new Response(200, [], $this->get_list_objects_body())]);

        $client = new \Aws\S3\S3Client($params);
        $client->listObjectsV2(['Bucket' => 'mybucket', 'Prefix' => 'dir/']);

        $this->assertStringStartsWith('https://s3.example.com/mybucket/', (string) $history[0]['request']->getUri());
    }

    /**
     * Test that requests are signed with AWS Signature Version 4.
     */
    public function test_request_is_signed_with_sigv4(): void {
        $history = [];
        $params = s3_client_factory::build_params('accesskey', 'secretkey', 'eu-central-1');
        $params['http_handler'] = $this->build_handler_stack($history, [new Response(200, [], $this->get_list_buckets_body())]);

        $client = new \Aws\S3\S3Client($params);
        $client->listBuckets();

        $this->assertStringStartsWith(
            'AWS4-HMAC-SHA256 Credential=accesskey/',
            $history[0]['request']->getHeaderLine('Authorization')
        );
    }

    /**
     * Test that a permanent redirect retries the request in the bucket region.
     */
    public function test_region_redirect_retries_with_bucket_region(): void {
        $history = [];
        $mock = new GuzzleMock([
            new Response(301, ['x-amz-bucket-region' => 'eu-central-1']),
            new Response(200, [], $this->get_list_objects_body()),
        ]);
        $stack = $this->build_handler_stack($history, $mock);
        $params = s3_client_factory::build_params('accesskey', 'secretkey', 'us-east-1');
        $params['http_handler'] = $stack;

        $client = new \Aws\S3\S3Client($params);
        s3_client_factory::install_region_redirect($client, static function (string $region) use ($stack): \Aws\S3\S3Client {
            $params = s3_client_factory::build_params('accesskey', 'secretkey', $region);
            $params['http_handler'] = $stack;
            return new \Aws\S3\S3Client($params);
        });
        $client->listObjectsV2(['Bucket' => 'mybucket', 'Prefix' => 'dir/']);

        $this->assertCount(2, $history);
        $this->assertStringContainsString('eu-central-1', (string) $history[1]['request']->getUri());
        $this->assertStringStartsWith(
            'AWS4-HMAC-SHA256 Credential=accesskey/',
            $history[1]['request']->getHeaderLine('Authorization')
        );
    }

    /**
     * Test that a permanent redirect without a bucket region header is not retried.
     */
    public function test_region_redirect_without_bucket_region_fails(): void {
        $history = [];
        $params = s3_client_factory::build_params('accesskey', 'secretkey', 'us-east-1');
        $params['http_handler'] = $this->build_handler_stack($history, [new Response(301, [])]);

        $client = new \Aws\S3\S3Client($params);
        s3_client_factory::install_region_redirect($client, static function (string $region): \Aws\S3\S3Client {
            $params = s3_client_factory::build_params('accesskey', 'secretkey', $region);
            return new \Aws\S3\S3Client($params);
        });

        $this->expectException(\Aws\S3\Exception\PermanentRedirectException::class);
        $client->listObjectsV2(['Bucket' => 'mybucket', 'Prefix' => 'dir/']);
    }

    /**
     * Build a Guzzle handler stack serving the given mocked responses.
     *
     * @param array $history The history array to record requests into.
     * @param array|GuzzleMock $responses The responses to serve.
     * @return \GuzzleHttp\HandlerStack The handler stack.
     */
    private function build_handler_stack(array &$history, array|GuzzleMock $responses): \GuzzleHttp\HandlerStack {
        $mock = is_array($responses) ? new GuzzleMock($responses) : $responses;
        $stack = \GuzzleHttp\HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        return $stack;
    }

    /**
     * Get the XML body for a list buckets response.
     *
     * @return string The XML body.
     */
    private function get_list_buckets_body(): string {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Owner><ID>1</ID><DisplayName>user</DisplayName></Owner>'
            . '<Buckets><Bucket><Name>testbucket</Name>'
            . '<CreationDate>2026-01-01T00:00:00.000Z</CreationDate></Bucket></Buckets>'
            . '</ListAllMyBucketsResult>';
    }

    /**
     * Get the XML body for a list objects response.
     *
     * @return string The XML body.
     */
    private function get_list_objects_body(): string {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Name>mybucket</Name><Prefix>dir/</Prefix><KeyCount>1</KeyCount><MaxKeys>1000</MaxKeys>'
            . '<IsTruncated>false</IsTruncated>'
            . '<Contents><Key>dir/file.txt</Key><LastModified>2026-01-01T00:00:00.000Z</LastModified>'
            . '<ETag>&quot;abc&quot;</ETag><Size>1024</Size><StorageClass>STANDARD</StorageClass></Contents>'
            . '</ListBucketResult>';
    }

    /**
     * Store the given configuration values.
     *
     * @param array $keys The configuration keys to store.
     * @return array The stored configuration.
     */
    private function store_config(array $keys): array {
        global $CFG;
        $stored = [];
        foreach ($keys as $key) {
            $stored[$key] = $CFG->$key ?? null;
        }
        return $stored;
    }

    /**
     * Restore the given configuration values.
     *
     * @param array $stored The stored configuration.
     */
    private function restore_config(array $stored): void {
        global $CFG;
        foreach ($stored as $key => $value) {
            if ($value !== null) {
                $CFG->$key = $value;
            } else {
                unset($CFG->$key);
            }
        }
    }
}
