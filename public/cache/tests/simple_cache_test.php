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

namespace core_cache;

use Psr\SimpleCache\CacheInterface;

/**
 * Unit tests for the PSR-16 SimpleCache wrapper over the MUC Cache.
 *
 * @package    core_cache
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \core_cache\simple_cache
 */
final class simple_cache_test extends \advanced_testcase {
    /** @var simple_cache The SimpleCache wrapper under test. */
    private simple_cache $sut;

    /** @var cache The MUC cache backing the wrapper. */
    private cache $muc;

    protected function setUp(): void {
        parent::setUp();
        $this->muc = cache::make_from_params(store::MODE_APPLICATION, 'core', 'rssfeed_test');
        $this->muc->purge();
        $this->sut = new simple_cache($this->muc);
    }

    /**
     * The wrapper must implement the PSR-16 CacheInterface.
     */
    public function test_implements_psr16(): void {
        $this->assertInstanceOf(CacheInterface::class, $this->sut);
    }

    /**
     * A missing key must return the provided default value (not throw).
     */
    public function test_get_missing_key_returns_default(): void {
        $this->assertSame('default', $this->sut->get('nothere', 'default'));
        $this->assertNull($this->sut->get('nothere'));
    }

    /**
     * Stored values must be retrievable, including array values which SimplePie uses.
     */
    public function test_set_and_get_roundtrip(): void {
        $value = ['a' => 1, 'b' => 'two', 'nested' => ['c' => 3]];

        $this->assertTrue($this->sut->set('mykey', $value));
        $this->assertSame($value, $this->sut->get('mykey'));
    }

    /**
     * Setting a key twice must overwrite the existing value.
     */
    public function test_set_overwrites_value(): void {
        $this->sut->set('mykey', 'first');
        $this->sut->set('mykey', 'second');

        $this->assertSame('second', $this->sut->get('mykey'));
    }

    /**
     * has() must reflect whether a key was stored.
     */
    public function test_has(): void {
        $this->assertFalse($this->sut->has('mykey'));

        $this->sut->set('mykey', 'value');
        $this->assertTrue($this->sut->has('mykey'));
    }

    /**
     * delete() must remove a stored key.
     */
    public function test_delete(): void {
        $this->sut->set('mykey', 'value');

        $this->assertTrue($this->sut->delete('mykey'));
        $this->assertFalse($this->sut->has('mykey'));
        $this->assertSame('default', $this->sut->get('mykey', 'default'));
    }

    /**
     * clear() must remove all keys.
     */
    public function test_clear(): void {
        $this->sut->set('key1', 'one');
        $this->sut->set('key2', 'two');

        $this->assertTrue($this->sut->clear());
        $this->assertFalse($this->sut->has('key1'));
        $this->assertFalse($this->sut->has('key2'));
    }

    /**
     * Multiple get/set/delete operations must behave like their single-key counterparts.
     */
    public function test_multiple_operations(): void {
        $values = ['key1' => 'one', 'key2' => 'two', 'key3' => 'three'];

        $this->assertTrue($this->sut->setMultiple($values));
        $this->assertSame($values, $this->sut->getMultiple(array_keys($values)));
        $this->assertSame(
            ['key1' => 'one', 'key2' => 'two', 'missing' => 'default'],
            $this->sut->getMultiple(['key1', 'key2', 'missing'], 'default')
        );

        $this->assertTrue($this->sut->deleteMultiple(['key1', 'key3']));
        $this->assertFalse($this->sut->has('key1'));
        $this->assertTrue($this->sut->has('key2'));
        $this->assertFalse($this->sut->has('key3'));
    }

    /**
     * Values set with an (already-expired) TTL must not be retrievable.
     */
    public function test_set_with_expired_ttl_is_not_retrievable(): void {
        $this->sut->set('mykey', 'value', -10);

        $this->assertSame('default', $this->sut->get('mykey', 'default'));
    }

    /**
     * A stored false value must be distinguishable from a cache miss.
     */
    public function test_stored_false_is_not_a_miss(): void {
        $this->assertTrue($this->sut->set('mykey', false));

        $this->assertTrue($this->sut->has('mykey'));
        $this->assertFalse($this->sut->get('mykey', 'default'));
        $this->assertFalse($this->sut->get('mykey'));
    }

    /**
     * A stored null value must be distinguishable from a cache miss.
     */
    public function test_stored_null_is_not_a_miss(): void {
        $this->assertTrue($this->sut->set('mykey', null));

        $this->assertTrue($this->sut->has('mykey'));
        $this->assertNull($this->sut->get('mykey', 'default'));
    }

    /**
     * A zero TTL must delete the entry immediately (PSR-16), not merely report it as missing:
     * the underlying MUC store must not retain the entry either.
     */
    public function test_set_with_zero_ttl_deletes(): void {
        $this->sut->set('mykey', 'value');

        $this->assertTrue($this->sut->set('mykey', 'value', 0));
        $this->assertFalse($this->sut->has('mykey'));
        $this->assertSame('default', $this->sut->get('mykey', 'default'));
        $this->assertFalse($this->muc->has('mykey'));
    }

    /**
     * A negative TTL must delete the entry immediately (PSR-16).
     */
    public function test_set_with_negative_ttl_deletes(): void {
        $this->sut->set('mykey', 'value');

        $this->assertTrue($this->sut->set('mykey', 'value', -10));
        $this->assertFalse($this->sut->has('mykey'));
        $this->assertSame('default', $this->sut->get('mykey', 'default'));
    }

    /**
     * A DateInterval TTL must be accepted, converted to seconds and stored as a real expiry
     * on the marker (PSR-16).
     */
    public function test_set_with_dateinterval_ttl(): void {
        $this->assertTrue($this->sut->set('mykey', 'value', new \DateInterval('PT1H')));

        $this->assertSame('value', $this->sut->get('mykey'));
        $this->assertTrue($this->sut->has('mykey'));

        // The stored marker must carry an expiry, so that the entry actually expires.
        $stored = $this->muc->get('mykey');
        $this->assertInstanceOf(simple_cache_value::class, $stored);
        $this->assertNotNull($stored->expires);
        $this->assertGreaterThan(time(), $stored->expires);
    }

    /**
     * A negative DateInterval TTL must delete the entry immediately (PSR-16).
     */
    public function test_set_with_negative_dateinterval_ttl_deletes(): void {
        $this->sut->set('mykey', 'value');

        $this->assertTrue($this->sut->set('mykey', 'value', \DateInterval::createFromDateString('-1 hour')));
        $this->assertFalse($this->sut->has('mykey'));
        $this->assertSame('default', $this->sut->get('mykey', 'default'));
    }

    /**
     * An entry whose expiry has passed must be reported as missing by get() and has(),
     * while a non-expired marker must still be found (so the test actually discriminates
     * on expiry rather than on the marker alone).
     *
     * Expiry is checked against the real clock (time()), not MUC's cache::now(), which
     * is frozen for the lifetime of the process.
     */
    public function test_has_returns_false_for_expired_entries(): void {
        // Simulate an entry left behind by a previous request, with an expiry that has already passed.
        $this->muc->set('expired', new simple_cache_value('value', time() - 10));

        $this->assertFalse($this->sut->has('expired'));
        // Reporting an expired entry as missing must also remove it from the store.
        $this->assertFalse($this->muc->has('expired'));

        $this->assertSame('default', $this->sut->get('expired', 'default'));

        // A non-expired marker must still be retrievable; without this control the test
        // would also pass if the expiry check were removed entirely.
        $this->muc->set('fresh', new simple_cache_value('value', time() + 3600));
        $this->assertTrue($this->sut->has('fresh'));
        $this->assertSame('value', $this->sut->get('fresh'));
    }

    /**
     * An entry with a one second TTL must no longer be readable after the TTL has elapsed,
     * even within the same process. MUC's cache::now() is frozen per process, so this also
     * guards against expiry being evaluated against the frozen clock.
     */
    public function test_entry_expires_in_real_time(): void {
        $this->assertTrue($this->sut->set('mykey', 'value', 1));
        $this->assertSame('value', $this->sut->get('mykey'));

        sleep(2);

        $this->assertFalse($this->sut->has('mykey'));
        $this->assertSame('default', $this->sut->get('mykey', 'default'));
    }

    /**
     * With MUC static acceleration enabled, a value set with a TTL must be immediately
     * retrievable. MUC's static acceleration returns the stored value without unwrapping
     * it, so the wrapper must not rely on MUC's ttl_wrapper for expiry.
     */
    public function test_static_acceleration_returns_stored_value(): void {
        $muc = cache::make_from_params(store::MODE_APPLICATION, 'core', 'rssfeed_test_staticaccel', [], [
            'staticacceleration' => true,
        ]);
        $muc->purge();
        $sut = new simple_cache($muc);

        $this->assertTrue($sut->set('mykey', 'value', 3600));
        $this->assertSame('value', $sut->get('mykey'));
        $this->assertTrue($sut->has('mykey'));
    }

    /**
     * A zero TTL must report failure when the delete failed and the old entry is still cached.
     */
    public function test_set_with_zero_ttl_reports_delete_failure(): void {
        $muc = $this->createMock(cache::class);
        $muc->method('get')->willReturn(new simple_cache_value('old value'));
        $muc->method('delete')->willReturn(false);
        $sut = new simple_cache($muc);

        $this->assertFalse($sut->set('mykey', 'new value', 0));
        $this->assertTrue($sut->has('mykey'));
    }

    /**
     * A zero TTL on a key which does not exist is still a success (PSR-16), even when the
     * underlying store reports the delete as failed.
     */
    public function test_set_with_zero_ttl_on_missing_key_is_success(): void {
        $muc = $this->createMock(cache::class);
        $muc->method('get')->willReturn(IGNORE_MISSING);
        $muc->method('delete')->willReturn(false);
        $sut = new simple_cache($muc);

        $this->assertTrue($sut->set('mykey', 'new value', 0));
    }

    /**
     * setMultiple() with a zero TTL must report failure when a delete failed and the old
     * entry is still cached.
     */
    public function test_set_multiple_with_zero_ttl_reports_delete_failure(): void {
        $muc = $this->createMock(cache::class);
        $muc->method('get')->willReturn(new simple_cache_value('old value'));
        $muc->method('delete_many')->willReturn(0);
        $sut = new simple_cache($muc);

        $this->assertFalse($sut->setMultiple(['mykey' => 'new value'], 0));
    }

    /**
     * Bulk methods must accept any iterable, not only arrays (PSR-16).
     */
    public function test_bulk_methods_accept_iterables(): void {
        $this->assertTrue($this->sut->setMultiple(new \ArrayIterator(['key1' => 'one', 'key2' => 'two'])));

        $this->assertSame(
            ['key1' => 'one', 'key2' => 'two'],
            $this->sut->getMultiple(new \ArrayIterator(['key1', 'key2']))
        );

        $this->assertTrue($this->sut->deleteMultiple(new \ArrayIterator(['key1', 'key2'])));
        $this->assertFalse($this->sut->has('key1'));
        $this->assertFalse($this->sut->has('key2'));
    }

    /**
     * getMultiple() and deleteMultiple() must not drop entries when the iterable repeats an
     * index, e.g. two generators both yielding index 0. iterator_to_array() without
     * $preservekeys would silently overwrite the first entry with the second.
     */
    public function test_bulk_methods_keep_entries_with_repeated_indexes(): void {
        $this->assertTrue($this->sut->setMultiple(['key1' => 'one', 'key2' => 'two']));

        $repeatedkeys = (function (): iterable {
            yield from ['key1'];
            yield from ['key2'];
        })();

        $this->assertSame(
            ['key1' => 'one', 'key2' => 'two'],
            $this->sut->getMultiple($repeatedkeys)
        );

        $this->assertTrue($this->sut->deleteMultiple((function (): iterable {
            yield from ['key1'];
            yield from ['key2'];
        })()));
        $this->assertFalse($this->sut->has('key1'));
        $this->assertFalse($this->sut->has('key2'));
    }

    /**
     * setMultiple() with a positive TTL must store the values with a real expiry.
     */
    public function test_set_multiple_with_dateinterval_ttl(): void {
        $this->assertTrue($this->sut->setMultiple(['key1' => 'one', 'key2' => 'two'], new \DateInterval('PT1H')));

        $this->assertSame(
            ['key1' => 'one', 'key2' => 'two'],
            $this->sut->getMultiple(['key1', 'key2'])
        );

        foreach (['key1', 'key2'] as $key) {
            $stored = $this->muc->get($key);
            $this->assertInstanceOf(simple_cache_value::class, $stored);
            $this->assertNotNull($stored->expires);
            $this->assertGreaterThan(time(), $stored->expires);
        }
    }

    /**
     * setMultiple() with a zero or negative TTL must delete the entries immediately (PSR-16),
     * not merely report them as missing: the underlying MUC store must not retain them either.
     *
     * The store is checked before has(), because has() removes expired entries it encounters,
     * which would mask a mutant that stores the entries with an immediate expiry instead of
     * deleting them.
     */
    public function test_set_multiple_with_zero_ttl_deletes(): void {
        $this->sut->setMultiple(['key1' => 'one', 'key2' => 'two']);

        $this->assertTrue($this->sut->setMultiple(['key1' => 'one', 'key2' => 'two'], 0));
        $this->assertFalse($this->muc->has('key1'));
        $this->assertFalse($this->muc->has('key2'));
        $this->assertFalse($this->sut->has('key1'));
        $this->assertFalse($this->sut->has('key2'));
    }

    /**
     * getMultiple() must report expired entries as missing and remove them from the store.
     */
    public function test_get_multiple_reports_expired_entries_as_missing(): void {
        $this->muc->set('expired', new simple_cache_value('old', time() - 10));
        $this->muc->set('fresh', new simple_cache_value('new', time() + 3600));

        $this->assertSame(
            ['expired' => 'default', 'fresh' => 'new'],
            $this->sut->getMultiple(['expired', 'fresh'], 'default')
        );
        $this->assertFalse($this->muc->has('expired'));
        $this->assertTrue($this->muc->has('fresh'));
    }
}
