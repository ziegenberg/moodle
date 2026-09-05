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

namespace core_blog;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blog/lib.php');

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

/**
 * Unit tests for external blog synchronisation (blog_sync_external_entries).
 *
 * Feeds are served from a Guzzle MockHandler injected into \core\http_client,
 * so the sync runs fully offline and deterministically.
 *
 * @package    core_blog
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::blog_sync_external_entries
 */
final class external_blog_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        \core\rss\reader::reset_cache();
    }

    /**
     * Build a \core\http_client backed by a Guzzle MockHandler serving the given responses.
     *
     * @param array $queue The queue of responses/exceptions to serve.
     * @return \core\http_client
     */
    private function get_mock_client(array $queue): \core\http_client {
        return new \core\http_client([
            'mock' => new MockHandler($queue),
            // SimplePie's Psr18Client follows redirects itself; Guzzle must not double-handle them.
            'allow_redirects' => false,
        ]);
    }

    /**
     * Get the contents of the shared rsstest.xml fixture (15 items).
     *
     * @return string
     */
    private function fixture(): string {
        global $CFG;
        return file_get_contents($CFG->dirroot . '/lib/tests/fixtures/rsstest.xml');
    }

    /**
     * Create a blog_external record.
     *
     * @param array $overrides Field overrides.
     * @return object The created record.
     */
    private function create_external_blog(array $overrides = []): object {
        global $DB;

        $record = (object) array_merge([
            'userid' => 1,
            'name' => 'Test external blog',
            'description' => 'A test external blog',
            'url' => 'http://example.com/feed.xml',
            'filtertags' => null,
            'failedlastsync' => 0,
            'timemodified' => time(),
            'timefetched' => 0,
        ], $overrides);
        $record->id = $DB->insert_record('blog_external', $record);

        return $record;
    }

    /**
     * Get the blog posts written for a given external blog.
     *
     * @param int $blogid The id of the external blog.
     * @return array The matching posts.
     */
    private function get_blog_posts(int $blogid): array {
        global $DB;

        $sql = "SELECT p.*
                  FROM {post} p
                 WHERE p.module = 'blog_external'
                       AND " . $DB->sql_compare_text('p.content') . " = " . $DB->sql_compare_text(':blogid') . "";

        return $DB->get_records_sql($sql, ['blogid' => (string) $blogid]);
    }

    /**
     * A successful sync must create a blog post for every item in the feed.
     */
    public function test_sync_creates_blog_entries_for_feed_items(): void {
        $this->resetAfterTest();
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $externalblog = $this->create_external_blog(['userid' => $user->id]);

        $result = blog_sync_external_entries($externalblog, $this->get_mock_client([
            new Response(200, ['Content-Type' => 'application/rss+xml'], $this->fixture()),
        ]));

        // The sync must not have failed.
        $this->assertNotFalse($result);

        // One post per feed item.
        $posts = $this->get_blog_posts($externalblog->id);
        $this->assertCount(15, $posts);

        // The first item is mapped onto a post.
        $ghop = array_values(array_filter(
            $posts,
            fn ($post) => $post->uniquehash === 'http://moodle.org/mod/forum/discuss.php?d=85629',
        ));
        $this->assertCount(1, $ghop);
        $ghop = reset($ghop);
        $this->assertSame('Google HOP contest encourages pre-University students to work on Moodle', $ghop->subject);
        $this->assertSame(1196412453, (int) $ghop->created);
        $this->assertEquals((int) $user->id, (int) $ghop->userid);
        $this->assertSame('site', $ghop->publishstate);

        // A successful sync persists the refreshed state.
        $updated = $DB->get_record('blog_external', ['id' => $externalblog->id]);
        $this->assertEquals(0, $updated->failedlastsync);
        $this->assertGreaterThan(0, $updated->timefetched);
    }

    /**
     * A failed fetch (e.g. a 404 response) must mark the external blog as failed.
     */
    public function test_sync_failed_fetch_marks_external_blog(): void {
        $this->resetAfterTest();
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $externalblog = $this->create_external_blog(['userid' => $user->id]);

        $result = blog_sync_external_entries($externalblog, $this->get_mock_client([
            new Response(404),
        ]));

        $this->assertFalse($result);
        $this->assertEmpty($this->get_blog_posts($externalblog->id));

        $updated = $DB->get_record('blog_external', ['id' => $externalblog->id]);
        $this->assertEquals(1, $updated->failedlastsync);
    }

    /**
     * A URL which is not a feed must mark the external blog as failed.
     */
    public function test_sync_non_feed_content_marks_external_blog(): void {
        $this->resetAfterTest();
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $externalblog = $this->create_external_blog(['userid' => $user->id]);

        $result = blog_sync_external_entries($externalblog, $this->get_mock_client([
            new Response(200, ['Content-Type' => 'text/html'], '<html><body>Not a feed</body></html>'),
        ]));

        $this->assertFalse($result);
        $this->assertEmpty($this->get_blog_posts($externalblog->id));

        $updated = $DB->get_record('blog_external', ['id' => $externalblog->id]);
        $this->assertEquals(1, $updated->failedlastsync);
    }

    /**
     * A previously failed external blog must be un-marked after a successful sync.
     */
    public function test_sync_clears_previous_failure(): void {
        $this->resetAfterTest();
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $externalblog = $this->create_external_blog([
            'userid' => $user->id,
            'failedlastsync' => 1,
        ]);

        $result = blog_sync_external_entries($externalblog, $this->get_mock_client([
            new Response(200, ['Content-Type' => 'application/rss+xml'], $this->fixture()),
        ]));

        $this->assertNotFalse($result);

        $updated = $DB->get_record('blog_external', ['id' => $externalblog->id]);
        $this->assertEquals(0, $updated->failedlastsync);
    }

    /**
     * A valid feed with no items must not fail the sync and must not create posts.
     */
    public function test_sync_empty_feed_creates_no_posts(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $externalblog = $this->create_external_blog(['userid' => $user->id]);

        $feed = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>'
            . '<title>Empty feed</title>'
            . '<link>http://example.com</link>'
            . '<description>An empty feed</description>'
            . '</channel></rss>';

        $result = blog_sync_external_entries($externalblog, $this->get_mock_client([
            new Response(200, ['Content-Type' => 'application/rss+xml'], $feed),
        ]));

        $this->assertNotFalse($result);
        $this->assertEmpty($this->get_blog_posts($externalblog->id));
    }

    /**
     * Entries may be filtered by category using the external blog's filtertags.
     */
    public function test_sync_filters_entries_by_category(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $externalblog = $this->create_external_blog([
            'userid' => $user->id,
            'filtertags' => 'news',
        ]);

        $feed = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>'
            . '<title>Filtered feed</title>'
            . '<link>http://example.com</link>'
            . '<item><title>News post</title>'
            . '<link>http://example.com/news</link>'
            . '<guid>http://example.com/news</guid>'
            . '<category>news</category>'
            . '</item>'
            . '<item><title>Tech post</title>'
            . '<link>http://example.com/tech</link>'
            . '<guid>http://example.com/tech</guid>'
            . '<category>tech</category>'
            . '</item>'
            . '</channel></rss>';

        $result = blog_sync_external_entries($externalblog, $this->get_mock_client([
            new Response(200, ['Content-Type' => 'application/rss+xml'], $feed),
        ]));

        $this->assertNotFalse($result);

        $posts = $this->get_blog_posts($externalblog->id);
        $this->assertCount(1, $posts);
        $this->assertSame('News post', reset($posts)->subject);
    }
}
