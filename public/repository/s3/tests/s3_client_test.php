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

use Aws\Api\DateTimeResult;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;

/**
 * Tests for the S3 client wrapper.
 *
 * @package    repository_s3
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group repository_s3
 */
final class s3_client_test extends \basic_testcase {
    /** @var int A fixed unix timestamp used in test responses. */
    private const TIMESTAMP = 1700000000;

    /**
     * Create an S3 client backed by the given mock handler.
     *
     * @param MockHandler $handler The handler that provides the API responses.
     * @return S3Client
     */
    private function get_s3_client(MockHandler $handler): S3Client {
        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'accesskey', 'secret' => 'secretkey'],
            'handler' => $handler,
        ]);
    }

    /**
     * Test that all pages of buckets are returned.
     */
    public function test_get_buckets(): void {
        $handler = new MockHandler([
            new Result([
                'Buckets' => [
                    ['Name' => 'bucket1', 'CreationDate' => DateTimeResult::fromEpoch(self::TIMESTAMP)],
                    ['Name' => 'bucket2', 'CreationDate' => DateTimeResult::fromEpoch(self::TIMESTAMP)],
                ],
            ]),
        ]);

        $s3 = new s3_client($this->get_s3_client($handler));
        $this->assertEquals(['bucket1', 'bucket2'], $s3->get_buckets());
    }

    /**
     * Test that an empty bucket list is returned when no buckets exist.
     */
    public function test_get_buckets_empty(): void {
        $handler = new MockHandler([
            new Result(['Buckets' => []]),
        ]);

        $s3 = new s3_client($this->get_s3_client($handler));
        $this->assertEquals([], $s3->get_buckets());
    }

    /**
     * Create an AWS exception suitable for use with the mock handler.
     *
     * @param string $message The exception message.
     * @param string $commandname The name of the command that failed.
     * @return AwsException
     */
    private function aws_exception(string $message, string $commandname): AwsException {
        return new AwsException($message, new Command($commandname), [
            'code' => 'TestError',
            'response' => new \GuzzleHttp\Psr7\Response(404),
        ]);
    }

    /**
     * Test that an AWS error is propagated to the caller.
     */
    public function test_get_buckets_error(): void {
        $handler = new MockHandler([
            $this->aws_exception('S3 error', 'ListBuckets'),
        ]);

        $s3 = new s3_client($this->get_s3_client($handler));
        $this->expectException(AwsException::class);
        $s3->get_buckets();
    }

    /**
     * Test that objects and folders are listed for a prefix, including additional pages.
     */
    public function test_list_objects(): void {
        $handler = new MockHandler([
            new Result([
                'Contents' => [
                    [
                        'Key' => 'dir/file1.txt',
                        'Size' => 100,
                        'LastModified' => DateTimeResult::fromEpoch(self::TIMESTAMP),
                    ],
                ],
                'CommonPrefixes' => [
                    ['Prefix' => 'dir/sub/'],
                ],
                'NextContinuationToken' => 'token',
            ]),
            new Result([
                'Contents' => [
                    [
                        'Key' => 'dir/file2.txt',
                        'Size' => 200,
                        'LastModified' => DateTimeResult::fromEpoch(self::TIMESTAMP + 60),
                    ],
                ],
                'CommonPrefixes' => [],
            ]),
        ]);

        $s3 = new s3_client($this->get_s3_client($handler));
        $listing = $s3->list_objects('bucket', 'dir/');

        $this->assertEquals(['dir/sub/'], $listing['folders']);
        $this->assertCount(2, $listing['objects']);
        $this->assertEquals('dir/file1.txt', $listing['objects'][0]['key']);
        $this->assertEquals(100, $listing['objects'][0]['size']);
        $this->assertEquals(self::TIMESTAMP, $listing['objects'][0]['lastmodified']);
        $this->assertEquals('dir/file2.txt', $listing['objects'][1]['key']);
        $this->assertEquals(200, $listing['objects'][1]['size']);
        $this->assertEquals(self::TIMESTAMP + 60, $listing['objects'][1]['lastmodified']);
    }

    /**
     * Test that an empty listing is returned when the prefix contains no objects.
     */
    public function test_list_objects_empty(): void {
        $handler = new MockHandler([
            new Result(['Contents' => [], 'CommonPrefixes' => []]),
        ]);

        $s3 = new s3_client($this->get_s3_client($handler));
        $this->assertEquals([
            'objects' => [],
            'folders' => [],
        ], $s3->list_objects('bucket', 'dir/'));
    }

    /**
     * Test that an AWS error is propagated to the caller.
     */
    public function test_list_objects_error(): void {
        $handler = new MockHandler([
            $this->aws_exception('S3 error', 'ListObjectsV2'),
        ]);

        $s3 = new s3_client($this->get_s3_client($handler));
        $this->expectException(AwsException::class);
        $s3->list_objects('bucket', 'dir/');
    }

    /**
     * Test that the object metadata is returned by a head request.
     */
    public function test_get_object_info(): void {
        $handler = new MockHandler([
            new Result([
                'ContentLength' => 1234,
                'LastModified' => DateTimeResult::fromEpoch(self::TIMESTAMP),
            ]),
        ]);

        $s3 = new s3_client($this->get_s3_client($handler));
        $this->assertEquals([
            'size' => 1234,
            'lastmodified' => self::TIMESTAMP,
        ], $s3->get_object_info('bucket', 'dir/file.txt'));
    }

    /**
     * Test that an AWS error is propagated to the caller.
     */
    public function test_get_object_info_error(): void {
        $handler = new MockHandler([
            $this->aws_exception('Not found', 'HeadObject'),
        ]);

        $s3 = new s3_client($this->get_s3_client($handler));
        $this->expectException(AwsException::class);
        $s3->get_object_info('bucket', 'dir/file.txt');
    }

    /**
     * Test that an object is downloaded to the requested local path.
     */
    public function test_get_object(): void {
        $localpath = tempnam(sys_get_temp_dir(), 's3test');
        $handler = new MockHandler([
            function ($command, $request) use ($localpath) {
                $this->assertEquals('bucket', $command['Bucket']);
                $this->assertEquals('dir/file.txt', $command['Key']);
                $this->assertEquals($localpath, $command['@http']['sink']);
                file_put_contents($command['@http']['sink'], 'file content');
                return new Result([]);
            },
        ]);

        $s3 = new s3_client($this->get_s3_client($handler));
        $s3->get_object('bucket', 'dir/file.txt', $localpath);

        $this->assertEquals('file content', file_get_contents($localpath));
        unlink($localpath);
    }

    /**
     * Test that an AWS error is propagated to the caller.
     */
    public function test_get_object_error(): void {
        $localpath = tempnam(sys_get_temp_dir(), 's3test');
        $handler = new MockHandler([
            $this->aws_exception('S3 error', 'GetObject'),
        ]);

        $s3 = new s3_client($this->get_s3_client($handler));
        $this->expectException(AwsException::class);
        $s3->get_object('bucket', 'dir/file.txt', $localpath);
    }
}
