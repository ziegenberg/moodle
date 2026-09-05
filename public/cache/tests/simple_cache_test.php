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
}
