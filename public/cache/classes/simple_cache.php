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
 * A SimpleCache MUC Wrapper to allow the MUC Cache to be used as a PSR-16 SimpleCache.
 *
 * @package    core_cache
 * @copyright  Andrew Lyons 2025 <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class simple_cache implements CacheInterface {
    /**
     * Create a new instance of the SimpleCache MUC Wrapper.
     *
     * @param \core_cache\cache $cache The MUC Cache Instance
     */
    public function __construct(
        /** @var \core_cache\cache The MUC Cache Instance */
        private cache $cache,
    ) {
    }

    #[\Override]
    public function get($key, $default = null): mixed {
        $result = $this->cache->get($key, IGNORE_MISSING);
        if ($result instanceof simple_cache_value) {
            if ($result->is_expired()) {
                $this->cache->delete($key);
                return $default;
            }
            return $result->data;
        }
        return $default;
    }

    #[\Override]
    public function set($key, $value, $ttl = null): bool {
        $ttl = self::normalise_ttl($ttl);

        if ($ttl !== null && $ttl <= 0) {
            // PSR-16: a zero or negative TTL means the item should be deleted immediately.
            // Deleting an item which does not exist is still a success, but a delete that
            // failed while the old entry is still cached is a failure.
            $this->delete($key);
            return !$this->has($key);
        }

        $expires = $ttl !== null ? time() + $ttl : null;
        return $this->cache->set($key, new simple_cache_value($value, $expires));
    }

    #[\Override]
    public function delete($key): bool {
        return $this->cache->delete($key);
    }

    #[\Override]
    public function clear(): bool {
        return $this->cache->purge();
    }

    #[\Override]
    public function getMultiple($keys, $default = null): iterable {
        $keys = self::normalise_iterable($keys, false);
        $found = $this->cache->get_many($keys);

        $result = [];
        foreach ($keys as $key) {
            $value = $found[$key] ?? false;
            if ($value instanceof simple_cache_value) {
                if ($value->is_expired()) {
                    $this->cache->delete($key);
                    $result[$key] = $default;
                } else {
                    $result[$key] = $value->data;
                }
            } else {
                $result[$key] = $default;
            }
        }
        return $result;
    }

    #[\Override]
    public function setMultiple($values, $ttl = null): bool {
        $values = self::normalise_iterable($values);
        $ttl = self::normalise_ttl($ttl);

        if ($ttl !== null && $ttl <= 0) {
            // PSR-16: a zero or negative TTL means the items should be deleted immediately.
            $keys = array_keys($values);
            $this->deleteMultiple($keys);
            foreach ($keys as $key) {
                if ($this->has($key)) {
                    return false;
                }
            }
            return true;
        }

        $expires = $ttl !== null ? time() + $ttl : null;
        $wrapped = [];
        foreach ($values as $key => $value) {
            $wrapped[$key] = new simple_cache_value($value, $expires);
        }
        return $this->cache->set_many($wrapped);
    }

    #[\Override]
    public function deleteMultiple($keys): bool {
        $keys = self::normalise_iterable($keys, false);
        $count = $this->cache->delete_many($keys);

        return ($count === count($keys));
    }

    #[\Override]
    public function has($key): bool {
        // Implement has() through the same lookup as get() so that expired entries are
        // reported as missing. MUC's cache::has() only checks the ttl_wrapper expiry when
        // the cache definition itself has a TTL, which is not the case for the ad-hoc
        // cache instances used through this wrapper.
        $result = $this->cache->get($key, IGNORE_MISSING);
        if ($result instanceof simple_cache_value) {
            if ($result->is_expired()) {
                $this->cache->delete($key);
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Normalise a TTL into seconds, or null when no expiry applies.
     *
     * PSR-16 allows an integer number of seconds or a DateInterval. The number
     * of seconds is then used to compute the absolute expiry timestamp.
     *
     * @param int|\DateInterval|null $ttl
     * @return int|null
     */
    private static function normalise_ttl($ttl): ?int {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();
            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        return (int) $ttl;
    }

    /**
     * Normalise an iterable of keys or values into an array.
     *
     * PSR-16 bulk methods accept any iterable, while the underlying MUC cache
     * only accepts arrays. $preservekeys controls whether iterator_to_array()
     * keeps the iterator's keys: when an iterator repeats an index (e.g. two
     * generators both yielding index 0), keeping the keys would silently drop
     * entries. getMultiple() and deleteMultiple() only use the values, so they
     * pass false; setMultiple() needs the keys, so it keeps them.
     *
     * @param iterable $items
     * @param bool $preservekeys Whether to preserve the keys of the iterable.
     * @return array
     */
    private static function normalise_iterable(iterable $items, bool $preservekeys = true): array {
        if (is_array($items)) {
            return $items;
        }
        return iterator_to_array($items, $preservekeys);
    }
}
