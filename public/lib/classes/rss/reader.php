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

use core\http_client;
use core_cache\cache;
use core_cache\simple_cache;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
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

        // Use Moodle's shared HTTP client for outgoing feed requests.
        if ($client === null) {
            $client = new http_client([
                // SimplePie's Psr18Client follows redirects itself (and tracks the
                // permanent URL), so disable Guzzle's own redirect handling.
                'allow_redirects' => false,
                'connect_timeout' => $timeout,
                'timeout' => $timeout,
            ]);
        }
        $factory = new HttpFactory();
        $this->set_http_client($client, $factory, $factory);

        // Use Moodle's HTMLPurifier for text cleaning.
        $this->get_registry()->register(Sanitize::class, reader_sanitize::class, true);
        $this->sanitize = new reader_sanitize();

        // Match Moodle encoding.
        $this->set_output_encoding('UTF-8');

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
     * Set the URL of the feed to be fetched or subscribed to.
     *
     * Only http(s) URLs are supported. SimplePie's PSR-18 client treats any
     * non-http(s) URL as a path to a local file and reads it from disk
     * (SimplePie\HTTP\Psr18Client::requestLocalFile()), so allowing a feed URL
     * to point at a local path would let a user with feed management rights read
     * arbitrary readable server files. Rejecting non-http(s) URLs here checks
     * every feed URL at the single choke point used by the blocks, the blog, and
     * cron alike.
     *
     * No exception is thrown for a rejected URL: the error is recorded on the
     * reader so callers that already check error() (block rendering, cron and the
     * feed forms) degrade gracefully, and any previously stored invalid feed URL
     * remains visible instead of fatalling the page. The feed URL is left unset,
     * so init() returns early without ever attempting a fetch.
     *
     * @param string|\Stringable $url The URL of the feed.
     */
    public function set_feed_url($url): void {
        if (is_array($url) || !preg_match('#^https?://#i', (string) $url)) {
            $this->error = get_string('invalidurl', 'error');
            return;
        }

        parent::set_feed_url($url);
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
