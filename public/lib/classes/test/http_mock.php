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

namespace core\test;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Generic HTTP mocking support for tests.
 *
 * Mocked responses are installed statically and are served by any \core\http_client instance
 * without reaching the real network. This is the mechanism used by Behat to make features
 * hermetic, and by PHPUnit tests that exercise code paths creating their own clients.
 *
 * The class lives in lib/classes so that it is autoloadable both in the Behat runner and in the
 * Behat site, which is served in a separate process. The site reloads the persisted mocks
 * whenever the shared file changes, while keeping the in-memory queues (served in order,
 * repeating the last response) between requests while the file is unchanged.
 *
 * @package    core
 * @category   test
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class http_mock {

    /**
     * Mocked HTTP responses, keyed by URL. Each URL maps to a queue of response descriptors
     * (arrays) which is consumed in order. Once the queue is exhausted, the last response is
     * repeated for any further requests to that URL.
     *
     * @var array|null
     */
    private static ?array $responses = null;

    /**
     * The modification time of the persisted mocked responses file the last time it was loaded.
     *
     * @var int|null
     */
    private static ?int $timestamp = null;

    /**
     * Install mocked HTTP responses for any subsequent http_client request.
     *
     * Only available for unit tests and Behat; throws a coding_exception otherwise.
     *
     * During Behat the mocked responses are persisted to a file in the shared Behat dataroot, so
     * that the Behat site (which is served in a separate process) can serve them.
     *
     * @param array $responses Map of URL identifiers to queues of responses. A queue may contain
     *                         \GuzzleHttp\Psr7\Response objects, or arrays with the keys
     *                         'status', 'headers' and 'body' describing a response.
     */
    public static function set_responses(array $responses): void {
        if (!defined('PHPUNIT_TEST') && !defined('BEHAT_TEST')) {
            throw new coding_exception('Mocked HTTP responses are only available for unit tests and Behat.');
        }

        self::$responses = self::normalise_responses($responses);

        if (defined('BEHAT_TEST')) {
            self::persist_responses();
        }
    }

    /**
     * Remove any mocked HTTP responses installed via set_responses().
     */
    public static function clear_responses(): void {
        self::$responses = null;
        self::$timestamp = null;

        if (defined('BEHAT_TEST')) {
            @unlink(self::get_filename());
        }
    }

    /**
     * Whether mocked HTTP responses have been installed.
     *
     * @return bool
     */
    public static function has_responses(): bool {
        if (defined('BEHAT_TEST') || defined('BEHAT_SITE_RUNNING')) {
            // The Behat runner and the Behat site are different processes. The site reloads the
            // persisted mocks whenever the file changes, but keeps consuming the in-memory queues
            // (served in order, repeating the last) between requests while the file is unchanged.
            $filename = self::get_filename();
            $mtime = @filemtime($filename);

            if ($mtime === false) {
                // No persisted mocks (or they were cleared).
                self::$responses = null;
                self::$timestamp = null;
                return false;
            }

            if ($mtime !== self::$timestamp) {
                self::$responses = self::read_persisted_responses();
                self::$timestamp = $mtime;
            }
        }

        return !empty(self::$responses);
    }

    /**
     * Get the Guzzle handler serving the mocked responses, or null when none are installed.
     *
     * @return callable|null
     */
    public static function get_handler(): ?callable {
        if (!self::has_responses()) {
            return null;
        }

        return function (RequestInterface $request, array $options = []): PromiseInterface {
            return Create::promiseFor(self::get_response($request));
        };
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
    private static function get_response(RequestInterface $request): Response {
        $url = self::url_key($request);

        if (empty(self::$responses[$url])) {
            return new Response(404);
        }

        $queue = &self::$responses[$url];
        $descriptor = array_shift($queue);

        // Repeat the last configured response once the queue is exhausted.
        if (empty($queue)) {
            self::$responses[$url] = [$descriptor];
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
    private static function url_key(RequestInterface $request): string {
        return $request->getUri()->withQuery('')->withFragment('')->__toString();
    }

    /**
     * Normalise a map of mocked responses into descriptor arrays.
     *
     * Response objects are converted to arrays with the keys 'status', 'headers' and 'body' so
     * that the mocks can be serialised and shared with the Behat site.
     *
     * @param array $responses The mocked responses to normalise.
     * @return array
     */
    private static function normalise_responses(array $responses): array {
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
     * The Behat runner and the Behat site share the dataroot, but run in separate processes, so
     * the mocked responses are written here by the runner and read by the site.
     *
     * @return string
     */
    private static function get_filename(): string {
        global $CFG;

        return $CFG->dataroot . '/behat_http_mocks.json';
    }

    /**
     * Persist the currently installed mocked responses to the shared Behat dataroot.
     */
    private static function persist_responses(): void {
        $content = json_encode(self::$responses);
        file_put_contents(self::get_filename(), $content, LOCK_EX);
    }

    /**
     * Read the mocked responses persisted in the shared Behat dataroot.
     *
     * @return array|null The mocked responses, or null if none have been installed.
     */
    private static function read_persisted_responses(): ?array {
        $filename = self::get_filename();
        if (!file_exists($filename)) {
            return null;
        }

        $responses = json_decode(file_get_contents($filename), true);

        return is_array($responses) ? $responses : null;
    }
}
