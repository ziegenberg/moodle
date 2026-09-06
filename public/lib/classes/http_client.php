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

use core\local\guzzle\cache_handler;
use core\local\guzzle\cache_storage;
use core\local\guzzle\check_request;
use core\local\guzzle\redirect_middleware;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Strategy\PrivateCacheStrategy;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle Integration for Moodle.
 *
 * @package   core
 * @copyright 2022 Andrew Lyons <andrew@nicols.co.uk>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class http_client extends Client {

    /**
     * Mocked HTTP responses, keyed by URL. Used by Behat (and PHPUnit) so that requests made through
     * any http_client instance can be served without reaching the real network.
     *
     * Each URL maps to a queue of response descriptors (arrays) which is consumed in order. Once the
     * queue is exhausted, the last response is repeated for any further requests to that URL.
     *
     * @var array|null
     */
    private static ?array $mockresponses = null;

    /**
     * The modification time of the persisted mocked responses file the last time it was loaded.
     *
     * Used by the Behat site (served in a separate process from the Behat runner) to reload changed
     * mocks without losing queue progress between requests within the same set of mocks.
     *
     * @var int|null
     */
    private static ?int $mocktimestamp = null;

    /**
     * Install mocked HTTP responses for any subsequent http_client request.
     *
     * Only available for unit tests and Behat; throws a coding_exception otherwise.
     *
     * During Behat the mocked responses are persisted to a file in the shared Behat dataroot, so that
     * the Behat site (which is served in a separate process) can serve them.
     *
     * @param array $responses Map of URL identifiers to queues of responses. A queue may contain
     *                        \GuzzleHttp\Psr7\Response objects, or arrays with the keys
     *                        'status', 'headers' and 'body' describing a response.
     */
    public static function set_mock_responses(array $responses): void {
        if (!defined('PHPUNIT_TEST') && !defined('BEHAT_TEST')) {
            throw new coding_exception('Mocked HTTP responses are only available for unit tests and Behat.');
        }

        self::$mockresponses = self::normalise_mock_responses($responses);

        if (defined('BEHAT_TEST')) {
            self::persist_mock_responses();
        }
    }

    /**
     * Remove any mocked HTTP responses installed via set_mock_responses().
     */
    public static function clear_mock_responses(): void {
        self::$mockresponses = null;
        self::$mocktimestamp = null;

        if (defined('BEHAT_TEST')) {
            @unlink(self::get_mock_filename());
        }
    }

    /**
     * Whether mocked HTTP responses have been installed.
     *
     * @return bool
     */
    public static function has_mock_responses(): bool {
        if (defined('BEHAT_TEST')) {
            // The Behat runner and the Behat site are different processes. The site reloads the
            // persisted mocks whenever the file changes, but keeps consuming the in-memory queues
            // (served in order, repeating the last) between requests while the file is unchanged.
            $filename = self::get_mock_filename();
            $mtime = @filemtime($filename);

            if ($mtime === false) {
                // No persisted mocks (or they were cleared).
                self::$mockresponses = null;
                self::$mocktimestamp = null;
                return false;
            }

            if ($mtime !== self::$mocktimestamp) {
                self::$mockresponses = self::read_persisted_mock_responses();
                self::$mocktimestamp = $mtime;
            }
        }

        return !empty(self::$mockresponses);
    }

    public function __construct(array $config = []) {
        $config = $this->get_options($config);

        parent::__construct($config);
    }

    /**
     * Get the custom options and handlers for guzzle integration in moodle.
     *
     * @param array $settings The settings or options from client.
     * @return array
     */
    protected function get_options(array $settings): array {
        if (empty($settings['handler'])) {
            // Configure the default handlers.
            $settings['handler'] = $this->get_handlers($settings);
        }

        // Request debugging {@link https://docs.guzzlephp.org/en/stable/request-options.html#debug}.
        if (!empty($settings[RequestOptions::DEBUG])) {
            // Accepts either a bool, or fopen resource.
            if (!is_resource($settings[RequestOptions::DEBUG])) {
                $settings[RequestOptions::DEBUG] = !empty($settings['debug']);
            }
        }

        // Proxy.
        $proxy = $this->setup_proxy($settings);
        if (!empty($proxy)) {
            $settings[RequestOptions::PROXY] = $proxy;
        }

        // Add the default user-agent header.
        if (!isset($settings['headers'])) {
            $settings['headers'] = ['User-Agent' => \core_useragent::get_moodlebot_useragent()];
        } else if (is_array($settings['headers'])) {
            $headers = array_keys(array_change_key_case($settings['headers']));
            // Add the User-Agent header if one was not already set.
            if (!in_array('user-agent', $headers)) {
                $settings['headers']['User-Agent'] = \core_useragent::get_moodlebot_useragent();
            }
        }

        return $settings;
    }

    /**
     * Get the handler stack according to the settings/options from client.
     *
     * @param array $settings The settings or options from client.
     * @return HandlerStack
     */
    protected function get_handlers(array $settings): HandlerStack {
        global $CFG;

        if (self::has_mock_responses()) {
            // Mocked responses have been installed (Behat). A routing handler serves the next
            // configured response for each URL without ever reaching the real network.
            $handler = function (RequestInterface $request, array $options = []): PromiseInterface {
                return Create::promiseFor(self::get_mocked_response($request));
            };
        } else if (isset($settings['mock'])) {
            // If a mock handler is set, add to stack. Mainly used for tests.
            $handler = $settings['mock'];
        } else {
            $handler = null;
        }

        if ($handler !== null) {
            $stack = HandlerStack::create($handler);
        } else {
            $stack = HandlerStack::create();
        }

        // Ensure that the first piece of middleware checks the block list.
        $stack->unshift(check_request::setup($settings), 'moodle_check_initial_request');

        // Replace the standard redirect handler with our custom Moodle one.
        // This handler checks the block list.
        // It extends the standard 'allow_redirects' handler so supports the same options.
        $stack->after('allow_redirects', redirect_middleware::setup($settings), 'moodle_allow_redirect');
        $stack->remove('allow_redirects');

        // Use cache middleware if cache is enabled.
        if (!empty($settings['cache'])) {
            $module = 'misc';
            if (!empty($settings['module_cache'])) {
                $module = $settings['module_cache'];
            }

            // Set TTL for the cache.
            if ($module === 'repository') {
                if (empty($CFG->repositorycacheexpire)) {
                    $CFG->repositorycacheexpire = 120;
                }
                $ttl = $CFG->repositorycacheexpire;
            } else {
                if (empty($CFG->curlcache)) {
                    $CFG->curlcache = 120;
                }
                $ttl = $CFG->curlcache;
            }

            $stack->push(new CacheMiddleware (new PrivateCacheStrategy (new cache_storage (new cache_handler($module), $ttl))),
                    'cache');
        }

        return $stack;
    }

    /**
     * Serve the next mocked response for the given request.
     *
     * Requests for URLs for which no response has been configured are treated as a 404 response,
     * rather than being allowed to reach the real network.
     *
     * @param RequestInterface $request The request being made.
     * @return Response
     */
    private static function get_mocked_response(RequestInterface $request): Response {
        $url = self::mocked_url_key($request);

        if (empty(self::$mockresponses[$url])) {
            return new Response(404);
        }

        $queue = &self::$mockresponses[$url];
        $descriptor = array_shift($queue);

        // Repeat the last configured response once the queue is exhausted.
        if (empty($queue)) {
            self::$mockresponses[$url] = [$descriptor];
        }

        return new Response($descriptor['status'], $descriptor['headers'], $descriptor['body']);
    }

    /**
     * Get the key used to match a request against the configured mocked responses.
     *
     * Requests are matched on scheme, host and path only - the query string is ignored.
     *
     * @param RequestInterface $request The request being made.
     * @return string
     */
    private static function mocked_url_key(RequestInterface $request): string {
        return $request->getUri()->withQuery('')->withFragment('')->__toString();
    }

    /**
     * Normalise a map of mocked responses into descriptor arrays.
     *
     * Response objects are converted to arrays with the keys 'status', 'headers' and 'body' so that
     * the mocks can be serialised and shared with the Behat site
     *
     * @param array $responses The mocked responses to normalise.
     * @return array
     */
    private static function normalise_mock_responses(array $responses): array {
        $normalised = [];
        foreach ($responses as $url => $queue) {
            $normalised[$url] = array_map(function ($entry): array {
                if ($entry instanceof Response) {
                    return [
                        'status' => $entry->getStatusCode(),
                        'headers' => $entry->getHeaders(),
                        'body' => (string) $entry->getBody(),
                    ];
                }

                return $entry;
            }, (array) $queue);
        }

        return $normalised;
    }

    /**
     * Get the path to the file used to persist mocked responses for Behat.
     *
     * The Behat runner and the Behat site share the dataroot, but run in separate processes, so the
     * mocked responses are written here by the runner and read by the site.
     *
     * @return string
     */
    private static function get_mock_filename(): string {
        global $CFG;

        return $CFG->dataroot . '/behat_http_mocks.json';
    }

    /**
     * Persist the currently installed mocked responses to the shared Behat dataroot.
     */
    private static function persist_mock_responses(): void {
        $content = json_encode(self::$mockresponses);
        file_put_contents(self::get_mock_filename(), $content, LOCK_EX);
    }

    /**
     * Read the mocked responses persisted in the shared Behat dataroot.
     *
     * @return array|null The mocked responses, or null if none have been installed.
     */
    private static function read_persisted_mock_responses(): ?array {
        $filename = self::get_mock_filename();
        if (!file_exists($filename)) {
            return null;
        }

        $responses = json_decode(file_get_contents($filename), true);

        return is_array($responses) ? $responses : null;
    }

    /**
     * Get the proxy configuration.
     *
     * @see {https://docs.guzzlephp.org/en/stable/request-options.html#proxy}
     * @param array $settings The incoming settings.
     * @return array The proxy settings
     */
    protected function setup_proxy(array $settings): ?array {
        global $CFG;

        if (empty($CFG->proxyhost)) {
            return null;
        }

        $proxy = $this->get_proxy($settings);
        $noproxy = [];

        if (!empty($CFG->proxybypass)) {
            $noproxy = array_map(function(string $hostname): string {
                return trim($hostname);
            }, explode(',', $CFG->proxybypass));
        }

        return [
            'http' => $proxy,
            'https' => $proxy,
            'no' => $noproxy,
        ];
    }

    /**
     * Get the proxy server identified.
     *
     * @param array $settings The incoming settings.
     * @return string The URI for the Proxy Server
     */
    protected function get_proxy(array $settings): string {
        global $CFG;
        $proxyhost = $CFG->proxyhost;
        if (!empty($CFG->proxyport)) {
            $proxyhost = "{$CFG->proxyhost}:{$CFG->proxyport}";
        }

        $proxyauth = "";
        if (!empty($CFG->proxyuser) && !empty($CFG->proxypassword)) {
            $proxyauth = "{$CFG->proxyuser}{$CFG->proxypassword}";
        }

        $protocol = "http://";
        if (!empty($CFG->proxytype) && $CFG->proxytype === 'SOCKS5') {
            $protocol = "socks5://";
        }

        return "{$protocol}{$proxyauth}{$proxyhost}";
    }
}
