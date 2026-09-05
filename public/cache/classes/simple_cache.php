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
        if (helper::result_found($result)) {
            return $result;
        }
        return $default;
    }

    #[\Override]
    public function set($key, $value, $ttl = null): bool {
        if ($ttl !== null) {
            $value = new ttl_wrapper($value, $ttl);
        }
        return $this->cache->set($key, $value);
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
        return array_map(
            fn ($result) => helper::result_found($result) ? $result : $default,
            $this->cache->get_many($keys),
        );
    }

    #[\Override]
    public function setMultiple($values, $ttl = null): bool {
        if ($ttl !== null) {
            $values = array_map(
                fn ($value) => new ttl_wrapper($value, $ttl),
                $values,
            );
        }
        return $this->cache->set_many($values);
    }

    #[\Override]
    public function deleteMultiple($keys): bool {
        $count = $this->cache->delete_many($keys);

        return ($count === count($keys));
    }

    #[\Override]
    public function has($key): bool {
        return $this->cache->has($key);
    }
}
