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

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Steps for the Amazon S3 repository.
 *
 * The HTTP requests made by the AWS SDK are routed through core\http_client, so
 * they can be mocked with the generic HTTP mocking steps (see behat_http.php).
 *
 * @package    repository_s3
 * @category   test
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_repository_s3 extends behat_base {

    /**
     * Create the Amazon S3 repository type with the given credentials.
     *
     * @Given /^the S3 repository is enabled with accesskey "(?P<k>[^"]*)" secretkey "(?P<s>[^"]*)"$/
     *
     * @param string $k The access key.
     * @param string $s The secret key.
     */
    public function the_s3_repository_is_enabled(string $k, string $s): void {
        $this->create_repository_type($k, $s, '');
    }

    /**
     * Create the Amazon S3 repository type configured for an S3-compatible endpoint.
     *
     * @Given /^the S3 repository is enabled with accesskey "(?P<k>[^"]*)" secretkey "(?P<s>[^"]*)" and endpoint "(?P<e>[^"]*)"$/
     *
     * @param string $k The access key.
     * @param string $s The secret key.
     * @param string $e The URL of an S3-compatible service.
     */
    public function the_s3_repository_is_enabled_with_endpoint(string $k, string $s, string $e): void {
        $this->create_repository_type($k, $s, $e);
    }

    /**
     * Create the Amazon S3 repository type.
     *
     * @param string $accesskey The access key.
     * @param string $secretkey The secret key.
     * @param string $endpoint The URL of an S3-compatible service, or an empty string for Amazon S3.
     */
    protected function create_repository_type(string $accesskey, string $secretkey, string $endpoint): void {
        $generator = \core\test\testing_util::get_data_generator();
        $generator->create_repository_type('s3', [
            'access_key' => $accesskey,
            'secret_key' => $secretkey,
            'api_region' => 'us-east-1',
            'endpoint' => $endpoint,
            // S3-compatible services use path-style addressing; Amazon S3 ignores this option.
            'use_path_style' => $endpoint === '' ? '0' : '1',
        ]);
    }
}
