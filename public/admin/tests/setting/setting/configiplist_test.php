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

/**
 * Unit tests for the admin_setting_configiplist class.
 *
 * @package    core_admin
 * @category   test
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(configiplist::class)]
final class configiplist_test extends \advanced_testcase {
    /**
     * Test that valid IPv4 and IPv6 addresses, ranges and partial addresses are accepted.
     *
     * @param string $setting the setting to validate.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('valid_iplist_provider')]
    public function test_iplist_valid_settings(string $setting): void {
        $this->resetAfterTest();

        $adminsetting = new configiplist('abc_cde/iplist', 'some desc', '', '');

        $errormessage = $adminsetting->write_setting($setting);
        $this->assertEmpty($errormessage, "Valid setting '$setting' produced error: $errormessage");
        $this->assertSame($setting, get_config('abc_cde', 'iplist'));
        $this->assertSame($setting, $adminsetting->get_setting());
    }

    /**
     * Data provider for test_iplist_valid_settings().
     *
     * Entries accepted by the ip blocker, including the IPv6 forms reported in MDL-81461.
     *
     * @return array
     */
    public static function valid_iplist_provider(): array {
        return [
            // Full IPv4 addresses.
            'Full IPv4 address' => ['192.168.10.1'],
            'Zero IPv4 address' => ['0.0.0.0'],
            'Maximum IPv4 address' => ['255.255.255.255'],
            // IPv4 CIDR ranges.
            'IPv4 CIDR range /24' => ['192.168.10.0/24'],
            'IPv4 CIDR range /20' => ['231.54.211.0/20'],
            // IPv4 last-group ranges.
            'IPv4 last-group range 10-20' => ['231.3.56.10-20'],
            'IPv4 last-group range to 255' => ['231.3.56.10-255'],
            // Partial IPv4 addresses.
            'Partial IPv4 address, single octet' => ['192'],
            'Partial IPv4 address, two octets' => ['192.168'],
            'Partial IPv4 address, trailing dot' => ['192.168.'],
            'Partial IPv4 address, three octets' => ['192.168.10'],
            // Full IPv6 addresses.
            'IPv6 loopback' => ['::1'],
            'IPv6 address, uncompressed' => ['0:0:0:0:0:0:0:1'],
            'IPv6 address, compressed' => ['fe80::'],
            'Full IPv6 address' => ['2001:db8:3333:4444:5555:6666:7777:8888'],
            // IPv6 CIDR ranges.
            'IPv6 CIDR range /64' => ['fe80::/64'],
            // IPv6 last-group ranges.
            'IPv6 last-group range' => ['fe80::1111-bbbb'],
            // Partial IPv6 addresses.
            'Partial IPv6 address, two groups' => ['fe80:1'],
            'Partial IPv6 address, four groups' => ['fe80:1:2:3'],
            'Partial IPv6 address, seven groups' => ['fe80:1:2:3:4:5:6'],
        ];
    }

    /**
     * Test that invalid IPv4 and IPv6 addresses are rejected.
     *
     * @param string $setting the setting to validate.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_iplist_provider')]
    public function test_iplist_invalid_settings(string $setting): void {
        $this->resetAfterTest();

        $adminsetting = new configiplist('abc_cde/iplist', 'some desc', '', '');

        $errormessage = $adminsetting->write_setting($setting);
        $this->assertNotSame('', $errormessage, "Invalid setting '$setting' was accepted");
        $this->assertFalse(get_config('abc_cde', 'iplist'));
    }

    /**
     * Data provider for test_iplist_invalid_settings().
     *
     * @return array
     */
    public static function invalid_iplist_provider(): array {
        return [
            // Out of range IPv4 octets.
            'Out of range octet 999' => ['999.999.999.999'],
            'Out of range first octet 256' => ['256.1.1.1'],
            'Out of range second octet 256' => ['1.256.1.1'],
            'Out of range last octet 256' => ['192.168.1.256'],
            'Out of range octet in partial address' => ['192.999'],
            // Invalid IPv4 CIDR masks and last-group ranges.
            'IPv4 mask larger than 32' => ['1.2.3.4/33'],
            'IPv4 range end larger than 255' => ['192.168.1.10-300'],
            'IPv4 range end smaller than start' => ['192.168.1.10-5'],
            // Partial IPv4 addresses with a CIDR mask are not supported.
            'Partial IPv4 address with /8 mask' => ['192/8'],
            'Partial IPv4 address with /16 mask' => ['192.168/16'],
            // Malformed IPv6 addresses.
            'Non-hex IPv6 address' => ['2001:dg8::1'],
            'Non-hex IPv6 range end' => ['fe80::1111-gggg'],
            // Too many groups to be a partial IPv6 address.
            'Partial IPv6 address with too many groups' => ['fe80:1:2:3:4:5:6:7:8'],
            // Too many octets to be an IPv4 address.
            'IPv4 address with too many octets' => ['192.168.10.1.1'],
            // Domain names and other non-IP input are not supported.
            'Hostname is not supported' => ['localhost'],
            'Domain name is not supported' => ['example.com'],
            'IPv4 address with non-numeric range end' => ['192.168.10.1-foo'],
            'Free text is not supported' => ['not-an-ip'],
        ];
    }

    /**
     * Test the return value of validate(), covering the error message content.
     *
     * @param string $list the list of entries to validate.
     * @param mixed $expected the expected return value.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('validate_result_provider')]
    public function test_iplist_validate(string $list, mixed $expected): void {
        $this->resetAfterTest();

        $adminsetting = new configiplist('abc_cde/iplist', 'some desc', '', '');

        $this->assertEquals($expected, $adminsetting->validate($list));
    }

    /**
     * Data provider for test_iplist_validate().
     *
     * @return array
     */
    public static function validate_result_provider(): array {
        return [
            // The error message reports all of the invalid entries.
            'error message lists every invalid entry' => [
                "192.168.1.1\n999.999.999.999\nfe80::/64\nnot-an-ip",
                'These IP addresses are invalid: 999.999.999.999, not-an-ip',
            ],
            // A list of valid entries passes validation.
            'all entries valid returns true' => [
                "123.123.123.123\n2001:db8::1",
                true,
            ],
        ];
    }

    /**
     * Test that blank lines and text following a "#" character are ignored.
     *
     * @param string $list the list of entries to validate.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ignored_lines_provider')]
    public function test_iplist_ignores_blank_lines_and_comments(string $list): void {
        $this->resetAfterTest();

        $adminsetting = new configiplist('abc_cde/iplist', 'some desc', '', '');

        $this->assertTrue($adminsetting->validate($list));
    }

    /**
     * Data provider for test_iplist_ignores_blank_lines_and_comments().
     *
     * @return array
     */
    public static function ignored_lines_provider(): array {
        return [
            'empty list' => [''],
            'blank lines and comments are ignored' => [
                "192.168.1.1\n# this is a comment\n\n   \nfe80::1 # and so is this\n",
            ],
        ];
    }
}
