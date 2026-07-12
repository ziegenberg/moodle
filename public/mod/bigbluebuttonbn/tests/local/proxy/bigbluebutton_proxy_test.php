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

namespace mod_bigbluebuttonbn\local\proxy;

use mod_bigbluebuttonbn\instance;
use mod_bigbluebuttonbn\test\testcase_helper_trait;

/**
 * Recording proxy tests class.
 *
 * @package   mod_bigbluebuttonbn
 * @copyright 2018 - present, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 * @covers  \mod_bigbluebuttonbn\local\proxy\bigbluebutton_proxy
 * @coversDefaultClass \mod_bigbluebuttonbn\local\proxy\bigbluebutton_proxy
 */
final class bigbluebutton_proxy_test extends \advanced_testcase {
    use testcase_helper_trait;

    /**
     * Test poll interval value
     *
     * @covers  \mod_bigbluebuttonbn\local\proxy\bigbluebutton_proxy::get_poll_interval
     * @return void
     */
    public function test_get_poll_interval(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->bigbluebuttonbn['poll_interval'] = 15;
        $this->assertEquals(15, bigbluebutton_proxy::get_poll_interval());
        $CFG->bigbluebuttonbn['poll_interval'] = 0;
        $this->assertEquals(bigbluebutton_proxy::MIN_POLL_INTERVAL, bigbluebutton_proxy::get_poll_interval());
    }

    /**
     * Extract the query string parameters from a proxy-generated join URL.
     *
     * @param string $joinurl
     * @return array
     */
    private function get_join_url_params(string $joinurl): array {
        $query = parse_url($joinurl, PHP_URL_QUERY);
        parse_str($query, $params);
        return $params;
    }

    /**
     * When guest access is not enabled for an instance, the join URL for a logged-in user must
     * not send guest=false, otherwise the BigBlueButton server ignores its own guestPolicy
     * setting and every logged-in user bypasses the moderator waiting room (MDL-76880).
     *
     * @covers  \mod_bigbluebuttonbn\local\proxy\bigbluebutton_proxy::get_join_url
     */
    public function test_get_join_url_omits_guest_param_when_guest_access_disabled(): void {
        $this->resetAfterTest();
        $this->initialise_mock_server();
        [, , $bbbactivity] = $this->create_instance(null, ['guestallowed' => 0]);
        $instance = instance::get_from_instanceid($bbbactivity->id);

        $joinurl = bigbluebutton_proxy::get_join_url($instance, null);

        $params = $this->get_join_url_params($joinurl);
        $this->assertArrayNotHasKey('guest', $params);
    }

    /**
     * When guest access is enabled for an instance, the join URL for a logged-in user must still
     * send guest=false so the server can tell the enrolled user apart from an external guest.
     *
     * @covers  \mod_bigbluebuttonbn\local\proxy\bigbluebutton_proxy::get_join_url
     */
    public function test_get_join_url_sends_guest_false_when_guest_access_enabled(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->initialise_mock_server();
        $CFG->bigbluebuttonbn['guestaccess_enabled'] = 1;
        [, , $bbbactivity] = $this->create_instance(null, ['guestallowed' => 1]);
        $instance = instance::get_from_instanceid($bbbactivity->id);

        $joinurl = bigbluebutton_proxy::get_join_url($instance, null);

        $params = $this->get_join_url_params($joinurl);
        $this->assertArrayHasKey('guest', $params);
        $this->assertEquals('false', $params['guest']);
    }

    /**
     * The dedicated guest join URL must always mark the joining user as a guest when guest
     * access is enabled for the instance, which is the only case it can be reached in practice
     * (see mod/bigbluebuttonbn/guest.php).
     *
     * @covers  \mod_bigbluebuttonbn\local\proxy\bigbluebutton_proxy::get_guest_join_url
     */
    public function test_get_guest_join_url_always_sends_guest_true(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->initialise_mock_server();
        $CFG->bigbluebuttonbn['guestaccess_enabled'] = 1;
        [, , $bbbactivity] = $this->create_instance(null, ['guestallowed' => 1]);
        $instance = instance::get_from_instanceid($bbbactivity->id);

        $joinurl = bigbluebutton_proxy::get_guest_join_url($instance, null, 'Guest user');

        $params = $this->get_join_url_params($joinurl);
        $this->assertArrayHasKey('guest', $params);
        $this->assertEquals('true', $params['guest']);
    }

    /**
     * A guest join url must never be built for an instance where guest access is disabled, since
     * mod/bigbluebuttonbn/guest.php is the only caller and it already blocks that case; the proxy
     * itself must fail loudly instead of silently building a URL that looks like an authenticated
     * join (MDL-76880).
     *
     * @covers  \mod_bigbluebuttonbn\local\proxy\bigbluebutton_proxy::get_guest_join_url
     */
    public function test_get_guest_join_url_throws_when_guest_access_disabled(): void {
        $this->resetAfterTest();
        $this->initialise_mock_server();
        [, , $bbbactivity] = $this->create_instance(null, ['guestallowed' => 0]);
        $instance = instance::get_from_instanceid($bbbactivity->id);

        $this->expectException(\coding_exception::class);
        bigbluebutton_proxy::get_guest_join_url($instance, null, 'Guest user');
    }
}
