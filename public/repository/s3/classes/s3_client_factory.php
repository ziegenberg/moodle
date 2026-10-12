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
use GuzzleHttp\RequestOptions;

/**
 * Creates S3 clients with Moodle specific configuration.
 *
 * The AWS SDK sends its HTTP requests through core\http_client, so the
 * Moodle HTTP settings apply to S3 requests as well: proxy and proxy
 * bypass, the security helper, and the HTTP response cache.
 *
 * @package    repository_s3
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class s3_client_factory {

    /**
     * Create an S3 client configured with the given credentials, region, endpoint and Moodle HTTP settings.
     *
     * @param string $accesskey The AWS access key.
     * @param string $secretkey The AWS secret key.
     * @param string $region The AWS region.
     * @param string $endpoint The URL of an S3-compatible service, or an empty string for the default AWS endpoint.
     * @param bool $usepathstyle Whether to use path-style bucket addressing.
     * @param array $settings Settings for core\http_client, e.g. cache, module_cache, ignoresecurity, securityhelper.
     * @return S3Client
     */
    public static function create_client(
        string $accesskey,
        string $secretkey,
        string $region,
        string $endpoint = '',
        bool $usepathstyle = false,
        array $settings = [],
    ): S3Client {
        $defaults = [
            'cache' => true,
            'module_cache' => 'repository',
        ];

        $client = new S3Client(self::build_params(
            $accesskey,
            $secretkey,
            $region,
            $endpoint,
            $usepathstyle,
            array_merge($defaults, $settings),
        ));
        self::install_region_redirect($client, static function (string $region) use (
            $accesskey,
            $secretkey,
            $endpoint,
            $usepathstyle,
            $settings,
        ): S3Client {
            return new S3Client(self::build_params($accesskey, $secretkey, $region, $endpoint, $usepathstyle, $settings));
        });

        return $client;
    }

    /**
     * Build the parameters for an S3 client.
     *
     * @param string $accesskey The AWS access key.
     * @param string $secretkey The AWS secret key.
     * @param string $region The AWS region.
     * @param string $endpoint The URL of an S3-compatible service, or an empty string for the default AWS endpoint.
     * @param bool $usepathstyle Whether to use path-style bucket addressing.
     * @param array $settings Settings for core\http_client, e.g. cache, module_cache, ignoresecurity, securityhelper.
     * @return array The parameters accepted by the S3Client constructor.
     */
    public static function build_params(
        string $accesskey,
        string $secretkey,
        string $region,
        string $endpoint = '',
        bool $usepathstyle = false,
        array $settings = [],
    ): array {
        $params = [
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $accesskey,
                'secret' => $secretkey,
            ],
        ];

        if ($endpoint !== '') {
            $params['endpoint'] = self::normalise_endpoint($endpoint);
            $params['use_path_style_endpoint'] = $usepathstyle;
        }

        // Send HTTP requests through the Moodle HTTP client so that the Moodle HTTP settings
        // apply: proxy and proxy bypass, security helper and response cache.
        $httpclient = new \core\http_client($settings);
        $params['http_handler'] = $httpclient->getConfig('handler');
        $params['http'] = [
            // The region redirect middleware handles S3 301 responses and re-signs the request;
            // the Guzzle redirect middleware cannot, so it must be disabled.
            'allow_redirects' => false,
        ];
        $proxy = $httpclient->getConfig(RequestOptions::PROXY);
        if (!empty($proxy)) {
            $params['http']['proxy'] = $proxy;
        }

        return $params;
    }

    /**
     * Install the region redirect middleware on an S3 client.
     *
     * @param S3Client $client The client to install the middleware on.
     * @param callable $clientfactory Creates a client for a given region.
     */
    public static function install_region_redirect(S3Client $client, callable $clientfactory): void {
        $client->getHandlerList()->prependInit(
            region_redirect_middleware::wrap($clientfactory, $client->getConfig('signing_region'))
        );
    }

    /**
     * Make sure an endpoint URL has a scheme, as the AWS SDK requires one.
     *
     * @param string $endpoint The endpoint URL, e.g. 's3.example.com'.
     * @return string The endpoint URL with a scheme, e.g. 'https://s3.example.com'.
     */
    public static function normalise_endpoint(string $endpoint): string {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $endpoint)) {
            return $endpoint;
        }
        return 'https://' . $endpoint;
    }
}
