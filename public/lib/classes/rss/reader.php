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

namespace core\rss;

use core\attribute\deprecated;
use core\deprecation;
use core\http_client;
use core_cache\cache;
use core_cache\simple_cache;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use SimplePie\Sanitize;
use SimplePie\SimplePie;

/**
 * Moodle customised version of the SimplePie class.
 *
 * Extends the stock SimplePie class in order to make sensible configuration
 * choices, such as using the shared Moodle cache (MUC) via a PSR-16 simple
 * cache, and the shared Moodle `core\http_client` (Guzzle) stack for making
 * HTTP requests, in line with Moodle configuration (proxy settings, user
 * agent, SSRF checks, cache).
 *
 * @package   core
 * @copyright 2009 Dan Poltawski <talktodan@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since     Moodle 2.0
 */
class reader extends SimplePie {
    /** @var ClientInterface|null The HTTP client used for feed requests. */
    private ?ClientInterface $httpclient = null;

    /**
     * Constructor - creates an instance of the SimplePie class with Moodle defaults.
     *
     * A PSR-18 HTTP client may be injected (e.g. a mock client in unit tests).
     * When none is provided, a Moodle `core\http_client` is created with an
     * explicit connect/timeout and redirects disabled.
     *
     * @param string|null $feedurl optional URL of the feed
     * @param int $timeout how many seconds requests should wait for server responses
     * @param ClientInterface|null $client optional PSR-18 HTTP client to use for feed requests
     */
    public function __construct(?string $feedurl = null, int $timeout = 2, ?ClientInterface $client = null) {
        parent::__construct();

        // Match Moodle encoding.
        $this->set_output_encoding('UTF-8');

        $this->timeout = $timeout;
        $this->httpclient = $client;
        if ($client === null) {
            $this->httpclient = $this->create_http_client();
        }
        $this->set_http_client($this->httpclient, new HttpFactory(), new HttpFactory());

        // Use Moodle's HTMLPurifier for text cleaning.
        $this->get_registry()->register(Sanitize::class, reader_sanitize::class, true);
        $this->sanitize = new reader_sanitize();

        // Use Moodle's shared cache (MUC), exposed as a PSR-16 simple cache, to
        // store parsed feed data. SimplePie manages a 1 hour expiry via the TTL.
        $this->set_cache(new simple_cache(self::get_cache_instance()));
        $this->set_cache_duration(3600);

        // Initialise the feed URL if passed in constructor.
        if ($feedurl !== null) {
            $this->set_feed_url($feedurl);
            $this->init();
        }
    }

    /**
     * Create a Moodle HTTP client with a timeout middleware.
     *
     * The current reader timeout is applied to each request by a middleware, so that
     * set_timeout() only needs to update the timeout property and never has to replace
     * the client (which may have been set by the caller via set_http_client()).
     *
     * @return http_client
     */
    private function create_http_client(): http_client {
        $client = new http_client([
            // SimplePie's Psr18Client follows redirects itself (and tracks the
            // permanent URL), so disable Guzzle's own redirect handling.
            'allow_redirects' => false,
        ]);

        // Apply the reader's current timeout to each request. The middleware is installed
        // after construction (before the stack is resolved by the first request), so the
        // default Moodle handlers (SSRF block list, redirects, cache) remain in place.
        $stack = $client->getConfig('handler');
        $stack->push(function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
                $options['connect_timeout'] = $this->timeout;
                $options['timeout'] = $this->timeout;
                return $handler($request, $options);
            };
        }, 'moodle_rss_timeout');

        return $client;
    }

    /**
     * Set the default timeout for fetching remote feeds.
     *
     * SimplePie's own set_timeout() has no effect (and triggers a notice) once
     * a PSR-18 client has been set with set_http_client(). The Moodle HTTP
     * client created by this reader applies the current timeout to each request
     * through a middleware, so this method only updates the timeout property and
     * never replaces a client installed through set_http_client().
     *
     * If a client was injected by the caller, the caller remains responsible
     * for configuring its timeout.
     *
     * @deprecated since Moodle 6.0 - use the $timeout constructor argument instead
     * @param int $timeout The maximum number of seconds to spend waiting to retrieve a feed.
     */
    #[deprecated(
        replacement: 'the $timeout argument of the \\core\\rss\\reader constructor',
        since: '6.0',
        reason: 'The timeout is configured on the HTTP client',
        mdl: 'MDL-89728',
    )]
    public function set_timeout(int $timeout = 10): void {
        \core\deprecation::emit_deprecation([self::class, __FUNCTION__]);

        $this->timeout = (int) $timeout;
    }

    /**
     * Get a Moodle cache instance to back the SimplePie feed cache.
     *
     * @return cache The Moodle Cache instance.
     */
    private static function get_cache_instance(): cache {
        return cache::make_from_params(\core_cache\store::MODE_APPLICATION, 'core', 'rssfeed');
    }

    /**
     * Reset the RSS feed cache.
     *
     * @return bool whether the cache was successfully cleared
     */
    public static function reset_cache(): bool {
        return self::get_cache_instance()->purge();
    }
}
