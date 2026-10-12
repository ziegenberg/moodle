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
use core\exception\moodle_exception;

/**
 * Tests for the repository_s3 class.
 *
 * @package    repository_s3
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group repository_s3
 */
final class repository_s3_test extends \advanced_testcase {
    /** @var int A fixed unix timestamp used in test responses. */
    private const TIMESTAMP = 1700000000;

    /** @var \repository_s3 The repository under test. */
    private \repository_s3 $repo;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->getDataGenerator()->create_repository_type('s3');
        $instances = \repository::get_instances(['type' => 's3']);
        $this->repo = new \repository_s3(array_pop($instances)->id);
    }

    /**
     * Replace the S3 client of the repository with a mock backed client.
     *
     * @param MockHandler $handler The handler that provides the API responses.
     */
    private function set_mock_s3_client(MockHandler $handler): void {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'accesskey', 'secret' => 'secretkey'],
            'handler' => $handler,
        ]);

        $property = new \ReflectionProperty(\repository_s3::class, 's3');
        $property->setValue($this->repo, new s3_client($client));
    }

    /**
     * Test listing the buckets at the root of the repository.
     */
    public function test_get_listing_buckets(): void {
        $handler = new MockHandler([
            new Result([
                'Buckets' => [
                    ['Name' => 'bucket1', 'CreationDate' => DateTimeResult::fromEpoch(self::TIMESTAMP)],
                    ['Name' => 'bucket2', 'CreationDate' => DateTimeResult::fromEpoch(self::TIMESTAMP)],
                ],
            ]),
        ]);
        $this->set_mock_s3_client($handler);

        $listing = $this->repo->get_listing();

        $this->assertEquals(['bucket1', 'bucket2'], array_column($listing['list'], 'title'));
        $this->assertEquals('bucket1', $listing['list'][0]['path']);
        $this->assertTrue($listing['dynload']);
        $this->assertTrue($listing['nologin']);
        $this->assertTrue($listing['nosearch']);
        $this->assertEquals([['path' => '', 'name' => 'Amazon S3']], $listing['path']);
    }

    /**
     * Test listing the content of a directory in a bucket.
     */
    public function test_get_listing_with_path(): void {
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
            ]),
        ]);
        $this->set_mock_s3_client($handler);

        $listing = $this->repo->get_listing('bucket/dir/');

        // Folders are listed before files.
        $this->assertCount(2, $listing['list']);
        $this->assertEquals('sub', $listing['list'][0]['title']);
        $this->assertEquals('bucket/dir/sub/', $listing['list'][0]['path']);
        $this->assertEquals('file1.txt', $listing['list'][1]['title']);
        $this->assertEquals('bucket/dir/file1.txt', $listing['list'][1]['source']);
        $this->assertEquals(100, $listing['list'][1]['size']);
        $this->assertEquals(self::TIMESTAMP, $listing['list'][1]['datemodified']);
        $this->assertEquals([
            ['path' => '', 'name' => 'Amazon S3'],
            ['path' => '/bucket', 'name' => 'bucket'],
            ['path' => '/bucket/dir', 'name' => 'dir'],
        ], $listing['path']);
    }

    /**
     * Test listing without configured access key.
     */
    public function test_get_listing_needaccesskey(): void {
        set_config('access_key', '', 's3');

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Access key must be provided');
        $this->repo->get_listing();
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
     * Test that a communication error is reported as a moodle exception.
     */
    public function test_get_listing_error(): void {
        $handler = new MockHandler([
            $this->aws_exception('S3 error', 'ListBuckets'),
        ]);
        $this->set_mock_s3_client($handler);

        $this->expectException(moodle_exception::class);
        $this->repo->get_listing();
    }

    /**
     * Test downloading a file from the repository.
     */
    public function test_get_file(): void {
        $handler = new MockHandler([
            function ($command, $request) {
                $this->assertEquals('bucket', $command['Bucket']);
                $this->assertEquals('dir/file.txt', $command['Key']);
                file_put_contents($command['@http']['sink'], 'file content');
                return new Result([]);
            },
        ]);
        $this->set_mock_s3_client($handler);

        $result = $this->repo->get_file('bucket/dir/file.txt', 'file.txt');

        $this->assertFileExists($result['path']);
        $this->assertEquals('file content', file_get_contents($result['path']));
    }

    /**
     * Test that a download error is reported as a moodle exception.
     */
    public function test_get_file_error(): void {
        $handler = new MockHandler([
            $this->aws_exception('S3 error', 'GetObject'),
        ]);
        $this->set_mock_s3_client($handler);

        $this->expectException(moodle_exception::class);
        $this->repo->get_file('bucket/dir/file.txt', 'file.txt');
    }

    /**
     * Test the supported return types.
     */
    public function test_supported_returntypes(): void {
        $this->assertEquals(FILE_INTERNAL | FILE_REFERENCE, $this->repo->supported_returntypes());
    }

    /**
     * Test the mapping of legacy endpoint values to regions.
     *
     * @dataProvider endpoint_to_region_provider
     * @param string $endpoint The legacy endpoint value.
     * @param string|null $expected The expected region, or null for custom endpoints.
     */
    public function test_endpoint_to_region(string $endpoint, ?string $expected): void {
        $this->assertEquals($expected, \repository_s3::endpoint_to_region($endpoint));
    }

    /**
     * Data provider for test_endpoint_to_region.
     *
     * @return array
     */
    public static function endpoint_to_region_provider(): array {
        return [
            'US East' => ['s3.amazonaws.com', 'us-east-1'],
            'US East external' => ['s3-external-1.amazonaws.com', 'us-east-1'],
            'US West 2' => ['s3-us-west-2.amazonaws.com', 'us-west-2'],
            'US West 1' => ['s3-us-west-1.amazonaws.com', 'us-west-1'],
            'EU West 1' => ['s3-eu-west-1.amazonaws.com', 'eu-west-1'],
            'EU Central dotted' => ['s3.eu-central-1.amazonaws.com', 'eu-central-1'],
            'EU Central dashed' => ['s3-eu-central-1.amazonaws.com', 'eu-central-1'],
            'AP Southeast 1' => ['s3-ap-southeast-1.amazonaws.com', 'ap-southeast-1'],
            'AP Southeast 2' => ['s3-ap-southeast-2.amazonaws.com', 'ap-southeast-2'],
            'AP Northeast 1' => ['s3-ap-northeast-1.amazonaws.com', 'ap-northeast-1'],
            'South America 1' => ['s3-sa-east-1.amazonaws.com', 'sa-east-1'],
            'Unknown endpoint returns null' => ['s3.custom.example.com', null],
        ];
    }

    /**
     * Test that the region options are loaded from the AWS SDK data file.
     */
    public function test_get_region_options(): void {
        $method = new \ReflectionMethod(\repository_s3::class, 'get_region_options');
        $options = $method->invoke(null);

        $this->assertArrayHasKey('us-east-1', $options);
        $this->assertArrayHasKey('eu-central-1', $options);
        $this->assertStringContainsString('us-east-1 - ', $options['us-east-1']);
    }

    /**
     * Test that the upgrade step migrates the legacy endpoint setting to a region.
     */
    public function test_upgrade_migrates_endpoint(): void {
        global $CFG;

        set_config('endpoint', 's3-eu-central-1.amazonaws.com', 's3');
        set_config('api_region', 'us-east-1', 's3');

        require_once($CFG->dirroot . '/repository/s3/db/upgrade.php');
        $this->assertTrue(xmldb_repository_s3_upgrade(2026101199));

        $this->assertEquals('eu-central-1', get_config('s3', 'api_region'));
        $this->assertFalse(get_config('s3', 'endpoint'));
    }

    /**
     * Test that the upgrade step keeps a custom endpoint for S3-compatible services.
     */
    public function test_upgrade_migrates_custom_endpoint(): void {
        global $CFG;

        set_config('endpoint', 'minio.example.com', 's3');
        unset_config('api_region', 's3');

        require_once($CFG->dirroot . '/repository/s3/db/upgrade.php');
        $this->assertTrue(xmldb_repository_s3_upgrade(2026101299));

        $this->assertEquals('https://minio.example.com', get_config('s3', 'endpoint'));
        $this->assertEquals('us-east-1', get_config('s3', 'api_region'));
    }

    /**
     * Test that the upgrade step keeps a default region when no endpoint is configured.
     */
    public function test_upgrade_without_endpoint(): void {
        global $CFG;

        set_config('api_region', 'ap-southeast-1', 's3');

        require_once($CFG->dirroot . '/repository/s3/db/upgrade.php');
        $this->assertTrue(xmldb_repository_s3_upgrade(2026101199));

        $this->assertEquals('ap-southeast-1', get_config('s3', 'api_region'));
    }

    /**
     * Test that synchronising a reference updates the file metadata.
     */
    public function test_sync_reference_updates_metadata(): void {
        $handler = new MockHandler([
            new Result([
                'ContentLength' => 1234,
                'LastModified' => DateTimeResult::fromEpoch(self::TIMESTAMP),
            ]),
        ]);
        $this->set_mock_s3_client($handler);

        $file = $this->create_reference_file('file.txt');
        $this->inject_repository_into_file($file);

        $this->assertEquals(1234, $file->get_filesize());
        $this->assertEquals(self::TIMESTAMP, $file->get_timemodified());
        $this->assertEquals(0, $file->get_status());
    }

    /**
     * Test that synchronising a reference to an image downloads the content for thumbnails.
     */
    public function test_sync_reference_image(): void {
        // A 1x1 transparent PNG.
        $content = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $handler = new MockHandler([
            new Result([
                'ContentLength' => strlen($content),
                'LastModified' => DateTimeResult::fromEpoch(self::TIMESTAMP),
            ]),
            function ($command, $request) use ($content) {
                file_put_contents($command['@http']['sink'], $content);
                return new Result([]);
            },
        ]);
        $this->set_mock_s3_client($handler);

        $file = $this->create_reference_file('image.png');
        $this->inject_repository_into_file($file);

        $this->assertEquals(strlen($content), $file->get_filesize());
        $this->assertEquals(\file_storage::hash_from_string($content), $file->get_contenthash());
    }

    /**
     * Test that a reference to a missing object is marked as lost.
     */
    public function test_sync_reference_missing_object(): void {
        $handler = new MockHandler([
            $this->aws_exception('Not found', 'HeadObject'),
        ]);
        $this->set_mock_s3_client($handler);

        $file = $this->create_reference_file('file.txt');
        $this->inject_repository_into_file($file);

        $this->assertEquals(0, $file->get_filesize());
        $this->assertEquals(666, $file->get_status());
    }

    /**
     * Create a reference file in the user draft area.
     *
     * @param string $filename The name of the referenced file.
     * @return \stored_file
     */
    private function create_reference_file(string $filename): \stored_file {
        $fs = get_file_storage();
        return $fs->create_file_from_reference([
            'contextid' => \context_system::instance()->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], $this->repo->id, 'bucket/dir/' . $filename);
    }

    /**
     * Replace the repository instance held by the stored file with the repository under test.
     *
     * The stored file loads its own repository instance when constructed, which would use the
     * real S3 client. This makes sure the mock backed client is used instead.
     *
     * @param \stored_file $file The reference file.
     */
    private function inject_repository_into_file(\stored_file $file): void {
        $property = new \ReflectionProperty(\stored_file::class, 'repository');
        $property->setValue($file, $this->repo);
    }
}
