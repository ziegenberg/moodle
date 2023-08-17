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

/**
 * Tests for the captcha feedback item.
 *
 * @package   mod_feedback
 * @copyright 2026 Moodle Pty Ltd
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_feedback;

use advanced_testcase;
use feedback_item_captcha;
use mod_feedback_complete_form;
use mod_feedback_completion;
use mod_feedback_structure;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/feedback/lib.php');
require_once($CFG->dirroot . '/mod/feedback/item/captcha/lib.php');

/**
 * Tests for the captcha feedback item.
 *
 * @package   mod_feedback
 * @copyright 2026 Moodle Pty Ltd
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(feedback_item_captcha::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('feedback_load_feedback_items_options')]
final class captcha_test extends advanced_testcase {
    /**
     * Test that the captcha item is only considered configured when both reCAPTCHA keys are set.
     */
    public function test_is_configured(): void {
        $this->resetAfterTest();

        set_config('recaptchapublickey', '');
        set_config('recaptchaprivatekey', '');
        $this->assertFalse(feedback_item_captcha::is_configured());

        set_config('recaptchapublickey', 'publickey');
        $this->assertFalse(feedback_item_captcha::is_configured());

        set_config('recaptchaprivatekey', 'privatekey');
        $this->assertTrue(feedback_item_captcha::is_configured());

        set_config('recaptchapublickey', '');
        $this->assertFalse(feedback_item_captcha::is_configured());
    }

    /**
     * Test that the captcha item is only offered in the add question menu when reCAPTCHA is configured.
     */
    public function test_item_offered_only_when_configured(): void {
        $this->resetAfterTest();

        set_config('recaptchapublickey', '');
        set_config('recaptchaprivatekey', '');
        $this->assertArrayNotHasKey('captcha', feedback_load_feedback_items_options());

        set_config('recaptchapublickey', 'publickey');
        set_config('recaptchaprivatekey', 'privatekey');
        $this->assertArrayHasKey('captcha', feedback_load_feedback_items_options());
    }

    /**
     * Test that a captcha item is treated as having no value when reCAPTCHA is not configured,
     * even if it was created while reCAPTCHA was configured.
     */
    public function test_item_has_no_value_when_not_configured(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $feedback = $this->getDataGenerator()->create_module('feedback', ['course' => $course]);
        $feedbackgenerator = $this->getDataGenerator()->get_plugin_generator('mod_feedback');

        set_config('recaptchapublickey', 'publickey');
        set_config('recaptchaprivatekey', 'privatekey');
        $item = $feedbackgenerator->create_item_captcha($feedback);
        $this->assertEquals(1, $item->hasvalue);

        set_config('recaptchapublickey', '');
        set_config('recaptchaprivatekey', '');

        $cm = get_fast_modinfo($course)->instances['feedback'][$feedback->id];
        $structure = new mod_feedback_structure($feedback, $cm);
        $items = $structure->get_items();
        $this->assertEquals(0, $items[$item->id]->hasvalue);

        // When configured again, the item gets a value again.
        set_config('recaptchapublickey', 'publickey');
        set_config('recaptchaprivatekey', 'privatekey');
        $structure = new mod_feedback_structure($feedback, $cm);
        $items = $structure->get_items();
        $this->assertEquals(1, $items[$item->id]->hasvalue);
    }

    /**
     * Test that a feedback with a captcha item which is no longer configured can still be completed.
     */
    public function test_completion_with_unconfigured_captcha(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $feedback = $this->getDataGenerator()->create_module('feedback', ['course' => $course]);
        $feedbackgenerator = $this->getDataGenerator()->get_plugin_generator('mod_feedback');

        // Create the captcha item while reCAPTCHA is configured (hasvalue stored as 1).
        set_config('recaptchapublickey', 'publickey');
        set_config('recaptchaprivatekey', 'privatekey');
        $feedbackgenerator->create_item_captcha($feedback);

        // Remove the keys afterwards, simulating a site where reCAPTCHA is later disabled.
        set_config('recaptchapublickey', '');
        set_config('recaptchaprivatekey', '');

        $cm = get_fast_modinfo($course)->instances['feedback'][$feedback->id];
        $completion = new mod_feedback_completion($feedback, $cm, $course->id);

        // The captcha is not rendered, so there is no incomplete page left.
        $this->assertNull($completion->get_next_page(0));
    }

    /**
     * Test the full completion of a feedback with an unconfigured captcha item.
     */
    public function test_full_completion_with_unconfigured_captcha(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $feedback = $this->getDataGenerator()->create_module('feedback', ['course' => $course]);
        $feedbackgenerator = $this->getDataGenerator()->get_plugin_generator('mod_feedback');

        // Create the captcha item while reCAPTCHA is configured, then remove the keys.
        set_config('recaptchapublickey', 'publickey');
        set_config('recaptchaprivatekey', 'privatekey');
        $item = $feedbackgenerator->create_item_captcha($feedback);
        set_config('recaptchapublickey', '');
        set_config('recaptchaprivatekey', '');

        $this->setUser($user);

        $cm = get_fast_modinfo($course)->instances['feedback'][$feedback->id];
        $completion = new mod_feedback_completion($feedback, $cm, $course->id);
        $this->assertTrue($completion->can_complete());
        $this->assertTrue($completion->can_submit());

        $_POST = mod_feedback_complete_form::mock_ajax_submit([
            'gopage' => 0,
            'savevalues' => get_string('save_entries', 'feedback'),
        ]);
        $urltogo = $completion->process_page(0);
        $this->assertNull($urltogo);
        $this->assertTrue($completion->just_completed());

        // The hidden captcha item must not store a response value.
        $this->assertCount(1, $DB->get_records('feedback_completed', ['feedback' => $feedback->id]));
        $this->assertCount(0, $DB->get_records('feedback_value', ['item' => $item->id]));
        $this->assertCount(0, $DB->get_records('feedback_valuetmp', ['item' => $item->id]));
    }
}
