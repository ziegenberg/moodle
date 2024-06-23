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

namespace core_badges;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/badgeslib.php');
require_once($CFG->dirroot . '/badges/lib.php');

use advanced_testcase;

/**
 * Badge events tests class.
 *
 * @package    core_badges
 * @copyright  2015 onwards Simey Lameze <simey@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class events_test extends advanced_testcase {

    /** @var $badgeid */
    protected $badgeid;

    /** @var $course */
    protected $course;

    /** @var $user */
    protected $user;

    /** @var $module */
    protected $module;

    /** @var $coursebadge */
    protected $coursebadge;

    /** @var $assertion to define json format for Open badge */
    protected $assertion;

    /** @var $assertion2 to define json format for Open badge version 2 */
    protected $assertion2;

    protected function setUp(): void {
        global $DB, $CFG;
        parent::setUp();
        $this->resetAfterTest(true);
        $CFG->enablecompletion = true;
        $user = $this->getDataGenerator()->create_user();
        $fordb = new \stdClass();
        $fordb->id = null;
        $fordb->name = "Test badge with 'apostrophe' and other friends (<>&@#)";
        $fordb->description = "Testing badges";
        $fordb->timecreated = time();
        $fordb->timemodified = time();
        $fordb->usercreated = $user->id;
        $fordb->usermodified = $user->id;
        $fordb->issuername = "Test issuer";
        $fordb->issuerurl = "http://issuer-url.domain.co.nz";
        $fordb->issuercontact = "issuer@example.com";
        $fordb->expiredate = null;
        $fordb->expireperiod = null;
        $fordb->type = BADGE_TYPE_SITE;
        $fordb->version = 1;
        $fordb->language = 'en';
        $fordb->courseid = null;
        $fordb->messagesubject = "Test message subject";
        $fordb->message = "Test message body";
        $fordb->attachment = 1;
        $fordb->notification = 0;
        $fordb->imageauthorname = "Image Author 1";
        $fordb->imageauthoremail = "author@example.com";
        $fordb->imageauthorurl = "http://author-url.example.com";
        $fordb->imagecaption = "Test caption image";
        $fordb->status = BADGE_STATUS_INACTIVE;

        $this->badgeid = $DB->insert_record('badge', $fordb, true);

        // Set the default Issuer (because OBv2 needs them).
        set_config('badges_defaultissuername', $fordb->issuername);
        set_config('badges_defaultissuercontact', $fordb->issuercontact);

        // Create a course with activity and auto completion tracking.
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => true]);
        $this->user = $this->getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $this->assertNotEmpty($studentrole);

        // Get manual enrolment plugin and enrol user.
        require_once($CFG->dirroot.'/enrol/manual/locallib.php');
        $manplugin = enrol_get_plugin('manual');
        $maninstance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $manplugin->enrol_user($maninstance, $this->user->id, $studentrole->id);
        $this->assertEquals(1, $DB->count_records('user_enrolments'));
        $completionauto = ['completion' => COMPLETION_TRACKING_AUTOMATIC];
        $this->module = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id], $completionauto);

        // Build badge and criteria.
        $fordb->type = BADGE_TYPE_COURSE;
        $fordb->courseid = $this->course->id;
        $fordb->status = BADGE_STATUS_ACTIVE;
        $this->coursebadge = $DB->insert_record('badge', $fordb, true);

        // Insert Endorsement.
        $endorsement = new \stdClass();
        $endorsement->badgeid = $this->coursebadge;
        $endorsement->issuername = "Issuer 123";
        $endorsement->issueremail = "issuer123@email.com";
        $endorsement->issuerurl = "https://example.org/issuer-123";
        $endorsement->dateissued = 1524567747;
        $endorsement->claimid = "https://example.org/robotics-badge.json";
        $endorsement->claimcomment = "Test endorser comment";
        $DB->insert_record('badge_endorsement', $endorsement, true);

        // Insert related badges.
        $badge = new badge($this->coursebadge);
        $clonedid = $badge->make_clone();
        $badgeclone = new badge($clonedid);
        $badgeclone->status = BADGE_STATUS_ACTIVE;
        $badgeclone->save();

        $relatebadge = new \stdClass();
        $relatebadge->badgeid = $this->coursebadge;
        $relatebadge->relatedbadgeid = $clonedid;
        $relatebadge->relatedid = $DB->insert_record('badge_related', $relatebadge, true);

        // Insert a aligment.
        $alignment = new \stdClass();
        $alignment->badgeid = $this->coursebadge;
        $alignment->targetname = 'CCSS.ELA-Literacy.RST.11-12.3';
        $alignment->targeturl = 'http://www.corestandards.org/ELA-Literacy/RST/11-12/3';
        $alignment->targetdescription = 'Test target description';
        $alignment->targetframework = 'CCSS.RST.11-12.3';
        $alignment->targetcode = 'CCSS.RST.11-12.3';
        $DB->insert_record('badge_alignment', $alignment, true);

        // Insert tags.
        \core_tag_tag::set_item_tags('core_badges', 'badge', $badge->id, $badge->get_context(), ['tag1', 'tag2']);

        $this->assertion = new \stdClass();
        $this->assertion->badge = '{"uid":"%s","recipient":{"identity":"%s","type":"email","hashed":true,"salt":"%s"},' .
            '"badge":"%s","verify":{"type":"hosted","url":"%s"},"issuedOn":"%d","evidence":"%s","tags":%s}';
        $this->assertion->class = '{"name":"%s","description":"%s","image":"%s","criteria":"%s","issuer":"%s","tags":%s}';
        $this->assertion->issuer = '{"name":"%s","url":"%s","email":"%s"}';
        // Format JSON-LD for Openbadge specification version 2.0.
        $this->assertion2 = new \stdClass();
        $this->assertion2->badge = '{"recipient":{"identity":"%s","type":"email","hashed":true,"salt":"%s"},' .
            '"badge":{"name":"%s","description":"%s","image":"%s",' .
            '"criteria":{"id":"%s","narrative":"%s"},"issuer":{"name":"%s","url":"%s","email":"%s",' .
            '"@context":"https:\/\/w3id.org\/openbadges\/v2","id":"%s","type":"Issuer"},' .
            '"tags":%s,"@context":"https:\/\/w3id.org\/openbadges\/v2","id":"%s","type":"BadgeClass","version":"%s",' .
            '"@language":"en","related":[{"id":"%s","version":"%s","@language":"%s"}],"endorsement":"%s",' .
            '"alignments":[{"targetName":"%s","targetUrl":"%s","targetDescription":"%s","targetFramework":"%s",' .
            '"targetCode":"%s"}]},"verify":{"type":"hosted","url":"%s"},"issuedOn":"%s","evidence":"%s","tags":%s,' .
            '"@context":"https:\/\/w3id.org\/openbadges\/v2","type":"Assertion","id":"%s"}';

        $this->assertion2->class = '{"name":"%s","description":"%s","image":"%s",' .
            '"criteria":{"id":"%s","narrative":"%s"},"issuer":{"name":"%s","url":"%s","email":"%s",' .
            '"@context":"https:\/\/w3id.org\/openbadges\/v2","id":"%s","type":"Issuer"},' .
            '"tags":%s,"@context":"https:\/\/w3id.org\/openbadges\/v2","id":"%s","type":"BadgeClass","version":"%s",' .
            '"@language":"%s","related":[{"id":"%s","version":"%s","@language":"%s"}],"endorsement":"%s",' .
            '"alignments":[{"targetName":"%s","targetUrl":"%s","targetDescription":"%s","targetFramework":"%s",' .
            '"targetCode":"%s"}]}';
        $this->assertion2->issuer = '{"name":"%s","url":"%s","email":"%s",' .
            '"@context":"https:\/\/w3id.org\/openbadges\/v2","id":"%s","type":"Issuer"}';
    }

    /**
     * Test badge awarded event.
     */
    public function test_badge_awarded(): void {

        $systemcontext = context_system::instance();

        $sink = $this->redirectEvents();

        $badge = new badge($this->badgeid);
        $badge->issue($this->user->id, true);
        $badge->is_issued($this->user->id);
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf('\core\event\badge_awarded', $event);
        $this->assertEquals($this->badgeid, $event->objectid);
        $this->assertEquals($this->user->id, $event->relateduserid);
        $this->assertEquals($systemcontext, $event->get_context());

        $sink->close();
    }

    /**
     * Test the badge created event.
     *
     * There is no external API for creating a badge, so the unit test will simply
     * create and trigger the event and ensure data is returned as expected.
     */
    public function test_badge_created(): void {

        $badge = new badge($this->badgeid);
        // Trigger an event: badge created.
        $eventparams = array(
            'userid' => $badge->usercreated,
            'objectid' => $badge->id,
            'context' => $badge->get_context(),
        );

        $event = \core\event\badge_created::create($eventparams);
        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\core\event\badge_created', $event);
        $this->assertEquals($badge->usercreated, $event->userid);
        $this->assertEquals($badge->id, $event->objectid);
        $this->assertDebuggingNotCalled();
        $sink->close();

    }

    /**
     * Test the badge archived event.
     *
     */
    public function test_badge_archived(): void {
        $badge = new badge($this->badgeid);
        $sink = $this->redirectEvents();

        // Trigger and capture the event.
        $badge->delete(true);
        $events = $sink->get_events();
        $this->assertCount(2, $events);
        $event = $events[1];

        // Check that the event data is valid.
        $this->assertInstanceOf('\core\event\badge_archived', $event);
        $this->assertEquals($badge->id, $event->objectid);
        $this->assertDebuggingNotCalled();
        $sink->close();

    }


    /**
     * Test the badge updated event.
     *
     */
    public function test_badge_updated(): void {
        $badge = new badge($this->badgeid);
        $sink = $this->redirectEvents();

        // Trigger and capture the event.
        $badge->save();
        $events = $sink->get_events();
        $event = reset($events);
        $this->assertCount(1, $events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\core\event\badge_updated', $event);
        $this->assertEquals($badge->id, $event->objectid);
        $this->assertDebuggingNotCalled();
        $sink->close();

    }
    /**
     * Test the badge deleted event.
     */
    public function test_badge_deleted(): void {
        $badge = new badge($this->badgeid);
        $sink = $this->redirectEvents();

        // Trigger and capture the event.
        $badge->delete(false);
        $events = $sink->get_events();
        $event = reset($events);
        $this->assertCount(1, $events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\core\event\badge_deleted', $event);
        $this->assertEquals($badge->id, $event->objectid);
        $this->assertDebuggingNotCalled();
        $sink->close();

    }

    /**
     * Test the badge duplicated event.
     *
     */
    public function test_badge_duplicated(): void {
        $badge = new badge($this->badgeid);
        $sink = $this->redirectEvents();

        // Trigger and capture the event.
        $newid = $badge->make_clone();
        $events = $sink->get_events();
        $event = reset($events);
        $this->assertCount(1, $events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\core\event\badge_duplicated', $event);
        $this->assertEquals($newid, $event->objectid);
        $this->assertDebuggingNotCalled();
        $sink->close();

    }

    /**
     * Test the badge disabled event.
     *
     */
    public function test_badge_disabled(): void {
        $badge = new badge($this->badgeid);
        $sink = $this->redirectEvents();

        // Trigger and capture the event.
        $badge->set_status(BADGE_STATUS_INACTIVE);
        $events = $sink->get_events();
        $event = reset($events);
        $this->assertCount(2, $events);
        $event = $events[1];

        // Check that the event data is valid.
        $this->assertInstanceOf('\core\event\badge_disabled', $event);
        $this->assertEquals($badge->id, $event->objectid);
        $this->assertDebuggingNotCalled();
        $sink->close();

    }

    /**
     * Test the badge enabled event.
     *
     */
    public function test_badge_enabled(): void {
        $badge = new badge($this->badgeid);
        $sink = $this->redirectEvents();

        // Trigger and capture the event.
        $badge->set_status(BADGE_STATUS_ACTIVE);
        $events = $sink->get_events();
        $event = reset($events);
        $this->assertCount(2, $events);
        $event = $events[1];

        // Check that the event data is valid.
        $this->assertInstanceOf('\core\event\badge_enabled', $event);
        $this->assertEquals($badge->id, $event->objectid);
        $this->assertDebuggingNotCalled();
        $sink->close();

    }

    /**
     * Test the badge criteria created event.
     *
     * There is no external API for this, so the unit test will simply
     * create and trigger the event and ensure data is returned as expected.
     */
    public function test_badge_criteria_created(): void {

        $badge = new badge($this->badgeid);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $criteriaoverall = award_criteria::build(array('criteriatype' => BADGE_CRITERIA_TYPE_OVERALL, 'badgeid' => $badge->id));
        $criteriaoverall->save(array('agg' => BADGE_CRITERIA_AGGREGATION_ALL));
        $criteriaprofile = award_criteria::build(array('criteriatype' => BADGE_CRITERIA_TYPE_PROFILE, 'badgeid' => $badge->id));
        $params = array('agg' => BADGE_CRITERIA_AGGREGATION_ALL, 'field_address' => 'address');
        $criteriaprofile->save($params);
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertCount(1, $events);
        $this->assertInstanceOf('\core\event\badge_criteria_created', $event);
        $this->assertEquals($criteriaprofile->id, $event->objectid);
        $this->assertEquals($criteriaprofile->badgeid, $event->other['badgeid']);
        $this->assertDebuggingNotCalled();
        $sink->close();

    }

    /**
     * Test the badge criteria updated event.
     *
     * There is no external API for this, so the unit test will simply
     * create and trigger the event and ensure data is returned as expected.
     */
    public function test_badge_criteria_updated(): void {

        $criteriaoverall = award_criteria::build(array('criteriatype' => BADGE_CRITERIA_TYPE_OVERALL, 'badgeid' => $this->badgeid));
        $criteriaoverall->save(array('agg' => BADGE_CRITERIA_AGGREGATION_ALL));
        $criteriaprofile = award_criteria::build(array('criteriatype' => BADGE_CRITERIA_TYPE_PROFILE, 'badgeid' => $this->badgeid));
        $params = array('agg' => BADGE_CRITERIA_AGGREGATION_ALL, 'field_address' => 'address');
        $criteriaprofile->save($params);
        $badge = new badge($this->badgeid);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $criteria = $badge->criteria[BADGE_CRITERIA_TYPE_PROFILE];
        $params2 = array('agg' => BADGE_CRITERIA_AGGREGATION_ALL, 'field_address' => 'address', 'id' => $criteria->id);
        $criteria->save((array)$params2);
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertCount(1, $events);
        $this->assertInstanceOf('\core\event\badge_criteria_updated', $event);
        $this->assertEquals($criteria->id, $event->objectid);
        $this->assertEquals($this->badgeid, $event->other['badgeid']);
        $this->assertDebuggingNotCalled();
        $sink->close();

    }

    /**
     * Test the badge criteria deleted event.
     *
     * There is no external API for this, so the unit test will simply
     * create and trigger the event and ensure data is returned as expected.
     */
    public function test_badge_criteria_deleted(): void {

        $criteriaoverall = award_criteria::build(array('criteriatype' => BADGE_CRITERIA_TYPE_OVERALL, 'badgeid' => $this->badgeid));
        $criteriaoverall->save(array('agg' => BADGE_CRITERIA_AGGREGATION_ALL));
        $badge = new badge($this->badgeid);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $badge->criteria[BADGE_CRITERIA_TYPE_OVERALL]->delete();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertCount(1, $events);
        $this->assertInstanceOf('\core\event\badge_criteria_deleted', $event);
        $this->assertEquals($criteriaoverall->badgeid, $event->other['badgeid']);
        $this->assertDebuggingNotCalled();
        $sink->close();

    }

    /**
     * Test the badge viewed event.
     *
     * There is no external API for viewing a badge, so the unit test will simply
     * create and trigger the event and ensure data is returned as expected.
     */
    public function test_badge_viewed(): void {

        $badge = new badge($this->badgeid);
        // Trigger an event: badge viewed.
        $other = array('badgeid' => $badge->id, 'badgehash' => '12345678');
        $eventparams = array(
            'context' => $badge->get_context(),
            'other' => $other,
        );

        $event = \core\event\badge_viewed::create($eventparams);
        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\core\event\badge_viewed', $event);
        $this->assertEquals('12345678', $event->other['badgehash']);
        $this->assertEquals($badge->id, $event->other['badgeid']);
        $this->assertDebuggingNotCalled();
        $sink->close();

    }

    /**
     * Test the badge listing viewed event.
     *
     * There is no external API for viewing a badge, so the unit test will simply
     * create and trigger the event and ensure data is returned as expected.
     */
    public function test_badge_listing_viewed(): void {

        // Trigger an event: badge listing viewed.
        $context = context_system::instance();
        $eventparams = array(
            'context' => $context,
            'other' => array('badgetype' => BADGE_TYPE_SITE)
        );

        $event = \core\event\badge_listing_viewed::create($eventparams);
        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\core\event\badge_listing_viewed', $event);
        $this->assertEquals(BADGE_TYPE_SITE, $event->other['badgetype']);
        $this->assertDebuggingNotCalled();
        $sink->close();

    }
}
