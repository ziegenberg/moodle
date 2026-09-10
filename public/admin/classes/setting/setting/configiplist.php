<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace core_admin\setting\setting;

use core\ip_utils;

/**
 * Used to validate a textarea used for ip addresses
 *
 * @package    core_admin
 * @copyright  2024 onwards Moodle Pty Ltd {@link https://moodle.com}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class configiplist extends \core_admin\setting\setting\configtextarea {
    /**
     * Validate the contents of the textarea as IP addresses
     *
     * Used to validate a new line separated list of IP addresses collected from
     * a textarea control
     *
     * @param string $data A list of IP Addresses separated by new lines
     * @return mixed bool true for success or string:error on failure
     */
    #[\Override]
    public function validate($data) {
        if (empty($data)) {
            return true;
        }

        $lines = explode("\n", $data);
        $badips = [];

        foreach ($lines as $line) {
            $tokens = explode('#', $line);
            $ip = trim($tokens[0]);
            if (empty($ip)) {
                continue;
            }

            if (!self::is_valid_iplist_entry($ip)) {
                $badips[] = $ip;
            }
        }

        if (count($badips) !== 0) {
            return get_string('validateiperror', 'admin', join(', ', $badips));
        }

        return true;
    }

    /**
     * Check whether a single entry is a valid IP address, address range or partial address.
     *
     * Validation is delegated to the well tested ip_utils where possible. In addition, the
     * partial-address formats understood by the IP blocker's subnet matching are supported
     * (eg. 192.168 or fe80:1).
     *
     * @param string $entry The entry to validate.
     * @return bool
     */
    private static function is_valid_iplist_entry(string $entry): bool {
        // Full IPv4 and IPv6 addresses.
        if (ip_utils::is_ip_address($entry)) {
            return true;
        }
        // Full IPv4 and IPv6 address ranges, using both CIDR and last-group range notation.
        // Eg. 231.54.211.0/20, 231.3.56.10-20, fe80::/64 and fe80::1111-bbbb.
        if (ip_utils::is_ipv4_range($entry) || ip_utils::is_ipv6_range($entry)) {
            return true;
        }
        // Partial IPv4 and IPv6 addresses.
        if (ip_utils::is_ipv4_partial_address($entry) || ip_utils::is_ipv6_partial_address($entry)) {
            return true;
        }
        return false;
    }
}

// Alias this class to the old name.
// This file will be autoloaded by the legacyclasses autoload system.
// In future all uses of this class will be corrected and the legacy references will be removed.
class_alias(configiplist::class, \admin_setting_configiplist::class);
