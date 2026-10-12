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

use Aws\S3\S3Client;

/**
 * Wrapper around the AWS SDK S3 client, providing the operations needed by the repository.
 *
 * @package    repository_s3
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class s3_client {
    /**
     * Constructor.
     *
     * @param S3Client $client The AWS SDK S3 client used to perform the API calls.
     */
    public function __construct(
        /** @var S3Client The AWS SDK S3 client used to perform the API calls. */
        private readonly S3Client $client,
    ) {
    }

    /**
     * Get the list of buckets accessible with the configured credentials.
     *
     * @return string[] The bucket names.
     */
    public function get_buckets(): array {
        $buckets = [];
        foreach ($this->client->getPaginator('ListBuckets') as $page) {
            foreach ($page['Buckets'] ?? [] as $bucket) {
                $buckets[] = $bucket['Name'];
            }
        }
        return $buckets;
    }

    /**
     * List the objects and folders (common prefixes) in a bucket below the given prefix.
     *
     * @param string $bucket The bucket name.
     * @param string $prefix The key prefix, without a leading slash.
     * @return array{
     *     objects: list<array{key: string, size: int, lastmodified: int}>,
     *     folders: list<string>,
     * }
     */
    public function list_objects(string $bucket, string $prefix): array {
        $objects = [];
        $folders = [];

        $paginator = $this->client->getPaginator('ListObjectsV2', [
            'Bucket' => $bucket,
            'Prefix' => $prefix,
            'Delimiter' => '/',
        ]);
        foreach ($paginator as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $objects[] = [
                    'key' => $object['Key'],
                    'size' => $object['Size'],
                    'lastmodified' => $object['LastModified']->getTimestamp(),
                ];
            }
            foreach ($page['CommonPrefixes'] ?? [] as $commonprefix) {
                $folders[] = $commonprefix['Prefix'];
            }
        }

        return [
            'objects' => $objects,
            'folders' => $folders,
        ];
    }

    /**
     * Get metadata about an object without downloading its content.
     *
     * @param string $bucket The bucket name.
     * @param string $key The object key.
     * @return array{size: int, lastmodified: int} The object size in bytes and last modified timestamp.
     */
    public function get_object_info(string $bucket, string $key): array {
        $result = $this->client->headObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);

        return [
            'size' => $result['ContentLength'],
            'lastmodified' => $result['LastModified']->getTimestamp(),
        ];
    }

    /**
     * Download an object and save its content to a local file.
     *
     * @param string $bucket The bucket name.
     * @param string $key The object key.
     * @param string $localpath The local path to save the object content to.
     */
    public function get_object(string $bucket, string $key, string $localpath): void {
        $this->client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'SaveAs' => $localpath,
        ]);
    }
}
