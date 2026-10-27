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

/**
 * Wraps a value stored by core_cache\simple_cache.
 *
 * MUC uses false to signal a cache miss, which is indistinguishable from a
 * stored false value. Wrapping every stored value in this marker allows
 * simple_cache to tell the two apart, as required by PSR-16.
 *
 * @package    core_cache
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class simple_cache_value {
    /**
     * @var mixed The value being stored.
     */
    public mixed $data;

    /**
     * Create a wrapper for a stored value.
     *
     * @param mixed $data The value being stored.
     */
    public function __construct(mixed $data) {
        $this->data = $data;
    }
}
