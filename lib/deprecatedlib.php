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
 * deprecatedlib.php - Old functions retained only for backward compatibility
 *
 * Old functions retained only for backward compatibility.  New code should not
 * use any of these functions.
 *
 * @package    core
 * @subpackage deprecated
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @deprecated
 */

defined('MOODLE_INTERNAL') || die();

/**
 * List all core subsystems and their location
 *
 * This is a list of components that are part of the core and their
 * language strings are defined in /lang/en/<<subsystem>>.php. If a given
 * plugin is not listed here and it does not have proper plugintype prefix,
 * then it is considered as course activity module.
 *
 * The location is optionally dirroot relative path. NULL means there is no special
 * directory for this subsystem. If the location is set, the subsystem's
 * renderer.php is expected to be there.
 *
 * @deprecated since 2.6, use core_component::get_core_subsystems()
 * @param bool $fullpaths false means relative paths from dirroot, use true for performance reasons
 * @return array of (string)name => (string|null)location
 */
#[\core\attribute\deprecated('core_component::get_core_subsystems', since: '4.5', mdl: 'MDL-82287')]
function get_core_subsystems($fullpaths = false) {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
    global $CFG;

    $subsystems = core_component::get_core_subsystems();

    if ($fullpaths) {
        return $subsystems;
    }

    debugging('Short paths are deprecated when using get_core_subsystems(), please fix the code to use fullpaths instead.', DEBUG_DEVELOPER);

    $dlength = strlen($CFG->dirroot);

    foreach ($subsystems as $k => $v) {
        if ($v === null) {
            continue;
        }
        $subsystems[$k] = substr($v, $dlength+1);
    }

    return $subsystems;
}

/**
 * Lists all plugin types.
 *
 * @deprecated since 2.6, use core_component::get_plugin_types()
 * @param bool $fullpaths false means relative paths from dirroot
 * @return array Array of strings - name=>location
 */
#[\core\attribute\deprecated('core_component::get_plugin_types', since: '4.5', mdl: 'MDL-82287')]
function get_plugin_types($fullpaths = true) {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
    global $CFG;

    $types = core_component::get_plugin_types();

    if ($fullpaths) {
        return $types;
    }

    debugging('Short paths are deprecated when using get_plugin_types(), please fix the code to use fullpaths instead.', DEBUG_DEVELOPER);

    $dlength = strlen($CFG->dirroot);

    foreach ($types as $k => $v) {
        if ($k === 'theme') {
            $types[$k] = 'theme';
            continue;
        }
        $types[$k] = substr($v, $dlength+1);
    }

    return $types;
}

/**
 * Use when listing real plugins of one type.
 *
 * @deprecated since 2.6, use core_component::get_plugin_list()
 * @param string $plugintype type of plugin
 * @return array name=>fulllocation pairs of plugins of given type
 */
#[\core\attribute\deprecated('core_component::get_plugin_list', since: '4.5', mdl: 'MDL-82287')]
function get_plugin_list($plugintype) {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);

    if ($plugintype === '') {
        $plugintype = 'mod';
    }

    return core_component::get_plugin_list($plugintype);
}

/**
 * Get a list of all the plugins of a given type that define a certain class
 * in a certain file. The plugin component names and class names are returned.
 *
 * @deprecated since 2.6, use core_component::get_plugin_list_with_class()
 * @param string $plugintype the type of plugin, e.g. 'mod' or 'report'.
 * @param string $class the part of the name of the class after the
 *      frankenstyle prefix. e.g 'thing' if you are looking for classes with
 *      names like report_courselist_thing. If you are looking for classes with
 *      the same name as the plugin name (e.g. qtype_multichoice) then pass ''.
 * @param string $file the name of file within the plugin that defines the class.
 * @return array with frankenstyle plugin names as keys (e.g. 'report_courselist', 'mod_forum')
 *      and the class names as values (e.g. 'report_courselist_thing', 'qtype_multichoice').
 */
#[\core\attribute\deprecated('core_component::get_plugin_list_with_class', since: '4.5', mdl: 'MDL-82287')]
function get_plugin_list_with_class($plugintype, $class, $file) {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
    return core_component::get_plugin_list_with_class($plugintype, $class, $file);
}

/**
 * Returns the exact absolute path to plugin directory.
 *
 * @deprecated since 2.6, use core_component::get_plugin_directory()
 * @param string $plugintype type of plugin
 * @param string $name name of the plugin
 * @return string full path to plugin directory; NULL if not found
 */
#[\core\attribute\deprecated('core_component::get_plugin_directory', since: '4.5', mdl: 'MDL-82287')]
function get_plugin_directory($plugintype, $name) {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
    if ($plugintype === '') {
        $plugintype = 'mod';
    }

    return core_component::get_plugin_directory($plugintype, $name);
}

/**
 * Normalize the component name using the "frankenstyle" names.
 *
 * @deprecated since 2.6, use core_component::normalize_component()
 * @param string $component
 * @return array two-items list of [(string)type, (string|null)name]
 */
#[\core\attribute\deprecated('core_component::normalize_component', since: '4.5', mdl: 'MDL-82287')]
function normalize_component($component) {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
    return core_component::normalize_component($component);
}

/**
 * Return exact absolute path to a plugin directory.
 *
 * @deprecated since 2.6, use core_component::normalize_component()
 *
 * @param string $component name such as 'moodle', 'mod_forum'
 * @return string full path to component directory; NULL if not found
 */
#[\core\attribute\deprecated('core_component::get_component_directory', since: '4.5', mdl: 'MDL-82287')]
function get_component_directory($component) {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
    return core_component::get_component_directory($component);
}

/**
 * @deprecated since 2.2, use context_course::instance() or other relevant class instead
 */
#[\core\attribute\deprecated('\core\context::instance', since: '2.2', mdl: 'MDL-34472', final: true)]
function get_context_instance() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since 2.5 - do not use, the textrotate.js will work it out automatically
 */
#[\core\attribute\deprecated('Not replaced', since: '2.0', mdl: 'MDL-19756', final: true)]
function can_use_rotated_text() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since 2.2
 */
#[\core\attribute\deprecated('\core\context\system::instance', since: '2.2', mdl: 'MDL-34472', final: true)]
function get_system_context() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * Returns an image of an up or down arrow, used for column sorting. To avoid unnecessary DB accesses, please
 * provide this function with the language strings for sortasc and sortdesc.
 *
 * @deprecated use $OUTPUT->arrow() instead.
 */
#[\core\attribute\deprecated('OUTPUT->[l|r]arrow', since: '2.0', mdl: 'MDL-19756', final: true)]
function print_arrow() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since Moodle 4.0
 */
#[\core\attribute\deprecated('category_action_bar tertiary navigation', since: '4.0', mdl: 'MDL-73462', final: true)]
function print_course_request_buttons() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since 4.2 Use \core\cron::run_main_process() instead.
 */
#[\core\attribute\deprecated('\core\cron::run_main_process()', since: '4.2', mdl: 'MDL-77186', final: true)]
function cron_run() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since 4.2 Use \core\cron::run_scheduled_tasks() instead.
 */
#[\core\attribute\deprecated('\core\cron::run_scheduled_tasks()', since: '4.2', mdl: 'MDL-77186', final: true)]
function cron_run_scheduled_tasks() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since 4.2 Use \core\cron::run_adhoc_tasks() instead.
 */
#[\core\attribute\deprecated('\core\cron::run_adhoc_tasks()', since: '4.2', mdl: 'MDL-77186', final: true)]
function cron_run_adhoc_tasks() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since 4.2 Use \core\cron::run_inner_scheduled_task() instead.
 */
#[\core\attribute\deprecated('\core\cron::run_inner_scheduled_task()', since: '4.2', mdl: 'MDL-77186', final: true)]
function cron_run_inner_scheduled_task() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since 4.2 Use \core\cron::run_inner_adhoc_task() instead.
 */
#[\core\attribute\deprecated('\core\cron::run_inner_adhoc_task()', since: '4.2', mdl: 'MDL-77186', final: true)]
function cron_run_inner_adhoc_task() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**

 * @deprecated since 4.2 Use \core\cron::set_process_title() instead.
 */
#[\core\attribute\deprecated('\core\cron::set_process_title()', since: '4.2', mdl: 'MDL-77186', final: true)]
function cron_set_process_title() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since 4.2 Use \core\cron::trace_time_and_memory() instead.
 */
#[\core\attribute\deprecated('\core\cron::trace_time_and_memory()', since: '4.2', mdl: 'MDL-77186', final: true)]
function cron_trace_time_and_memory() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since 4.2 Use \core\cron::prepare_core_renderer() instead.
 */
#[\core\attribute\deprecated('\core\cron::prepare_core_renderer()', since: '4.2', mdl: 'MDL-77186', final: true)]
function cron_prepare_core_renderer() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since 4.2. Use \core\core::setup_user() instead.
 */
#[\core\attribute\deprecated('\core\core::setup_user()', since: '4.2', mdl: 'MDL-77837', final: true)]
function cron_setup_user() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since 4.3.
 */
#[\core\attribute\deprecated(since: '4.2', mdl: 'MDL-77837', final: true)]
function badges_get_oauth2_service_options() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since 4.3.
 */
#[\core\attribute\deprecated(
    since: '4.2',
    reason: 'All functions associated with device specific themes are being removed',
    mdl: 'MDL-77793',
    final: true,
)]
function theme_is_device_locked() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since 4.3.
 */
#[\core\attribute\deprecated(
    since: '4.2',
    reason: 'All functions associated with device specific themes are being removed',
    mdl: 'MDL-77793',
    final: true,
)]
function theme_get_locked_theme_for_device() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since 4.3.
 */
#[\core\attribute\deprecated(
    'random_bytes()',
    since: '4.2',
    reason: 'Since PHP 7.0 the random_bytes() is nativley available and Moodle LMS requires greater than PHP 7.',
    mdl: 'MDL-78698',
    final: true,
)]
function random_bytes_emulate() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * rc4encrypt
 *
 * @param string $data        Data to encrypt.
 * @return string             The now encrypted data.
 *
 * @deprecated since Moodle 4.5 - please do not use this function any more, {@see \core\encryption::encrypt}
 */
#[\core\attribute\deprecated('\core\encryption::encrypt', since: '4.5', mdl: 'MDL-81940')]
function rc4encrypt($data) {
    // No initial deprecation notice here, as the following method triggers its own.
    return endecrypt(get_site_identifier(), $data, '');
}

/**
 * rc4decrypt
 *
 * @param string $data        Data to decrypt.
 * @return string             The now decrypted data.
 *
 * @deprecated since Moodle 4.5 - please do not use this function any more, {@see \core\encryption::decrypt}
 */
#[\core\attribute\deprecated('\core\encryption::decrypt', since: '4.5', mdl: 'MDL-81940')]
function rc4decrypt($data) {
    // No initial deprecation notice here, as the following method triggers its own.
    return endecrypt(get_site_identifier(), $data, 'de');
}

/**
 * Based on a class by Mukul Sabharwal [mukulsabharwal @ yahoo.com]
 *
 * @param string $pwd The password to use when encrypting or decrypting
 * @param string $data The data to be decrypted/encrypted
 * @param string $case Either 'de' for decrypt or '' for encrypt
 * @return string
 *
 * @deprecated since Moodle 4.5 - please do not use this function any more, {@see \core\encryption}
 */
#[\core\attribute\deprecated(\core\encryption::class, since: '4.5', mdl: 'MDL-81940')]
function endecrypt($pwd, $data, $case) {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);

    if ($case == 'de') {
        $data = urldecode($data);
    }

    $key[] = '';
    $box[] = '';
    $pwdlength = strlen($pwd);

    for ($i = 0; $i <= 255; $i++) {
        $key[$i] = ord(substr($pwd, ($i % $pwdlength), 1));
        $box[$i] = $i;
    }

    $x = 0;

    for ($i = 0; $i <= 255; $i++) {
        $x = ($x + $box[$i] + $key[$i]) % 256;
        $tempswap = $box[$i];
        $box[$i] = $box[$x];
        $box[$x] = $tempswap;
    }

    $cipher = '';

    $a = 0;
    $j = 0;

    for ($i = 0; $i < strlen($data); $i++) {
        $a = ($a + 1) % 256;
        $j = ($j + $box[$a]) % 256;
        $temp = $box[$a];
        $box[$a] = $box[$j];
        $box[$j] = $temp;
        $k = $box[(($box[$a] + $box[$j]) % 256)];
        $cipherby = ord(substr($data, $i, 1)) ^ $k;
        $cipher .= chr($cipherby);
    }

    if ($case == 'de') {
        $cipher = urldecode(urlencode($cipher));
    } else {
        $cipher = urlencode($cipher);
    }

    return $cipher;
}

/**
 * @deprecated since Moodle 4.0
 */
function question_preview_url() {
    throw new coding_exception(__FUNCTION__ . '() has been removed.');
}

/**
 * @deprecated since Moodle 4.0
 */
function question_preview_popup_params() {
    throw new coding_exception(__FUNCTION__ . '() has been removed.');
}

/**
 * @deprecated since Moodle 4.0
 */
function question_hash() {
    throw new coding_exception(__FUNCTION__ . '() has been removed.');
}

/**
 * @deprecated since Moodle 4.0 MDL-71573
 */
function question_make_export_url() {
    throw new coding_exception(__FUNCTION__ . '() has been removed.');
}

/**
 * @deprecated since Moodle 4.0
 */
function question_get_export_single_question_url() {
    throw new coding_exception(__FUNCTION__ . '() has been removed.');
}

/**
 * @deprecated since Moodle 4.0 MDL-71585
 */
function question_remove_stale_questions_from_category() {
    throw new coding_exception(__FUNCTION__ . '() has been removed.');
}

/**
 * @deprecated since Moodle 4.0 MDL-71585
 */
function flatten_category_tree() {
    throw new coding_exception(__FUNCTION__ . '() has been removed.');
}

/**
 * @deprecated since Moodle 4.0 MDL-71585
 */
function add_indented_names() {
    throw new coding_exception(__FUNCTION__ . '() has been removed.');
}

/**
 * @deprecated since Moodle 4.0 MDL-71585
 */
function question_category_select_menu() {
    throw new coding_exception(__FUNCTION__ . '() has been removed.');
}

/**
 * @deprecated since Moodle 4.0 MDL-71585
 */
function get_categories_for_contexts() {
    throw new coding_exception(__FUNCTION__ . '() has been removed.');
}

/**
 * @deprecated since Moodle 4.0 MDL-71585
 */
function question_category_options() {
    throw new coding_exception(__FUNCTION__ . '() has been removed.');
}

/**
 * @deprecated since Moodle 4.0 MDL-71585
 */
function question_add_context_in_key() {
    throw new coding_exception(__FUNCTION__ . '() has been removed.');
}

/**
 * @deprecated since Moodle 4.0 MDL-71585
 */
function question_fix_top_names() {
    throw new coding_exception(__FUNCTION__ . '() has been removed.');
}

/**
 * @deprecated since Moodle 2.9
 */
#[\core\attribute\deprecated('search_generate_SQL', since: '2.9', mdl: 'MDL-48939', final: true)]
function search_generate_text_SQL() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated Since Moodle 4.5
 */
#[\core\attribute\deprecated('This method should not be used', since: '4.5', mdl: 'MDL-80275', final: true)]
function disable_output_buffering(): void {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}


/**
 * Prints a grade menu (as part of an existing form) with help showing all possible numerical grades and scales.
 *
 * @todo Finish documenting this function
 * @todo Deprecate: this is only used in a few contrib modules
 *
 * @param int $courseid The course ID
 * @param string $name
 * @param string $current
 * @param boolean $includenograde Include those with no grades
 * @param boolean $return If set to true returns rather than echo's
 * @return string|bool|null Depending on value of $return
 * @deprecated Since Moodle 4.5
 */
#[\core\attribute\deprecated('This method should not be used', since: '4.5', mdl: 'MDL-82157')]
function print_grade_menu($courseid, $name, $current, $includenograde=true, $return=false) {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
    global $OUTPUT;

    $output = '';
    $strscale = get_string('scale');
    $strscales = get_string('scales');

    $scales = get_scales_menu($courseid);
    foreach ($scales as $i => $scalename) {
        $grades[-$i] = $strscale .': '. $scalename;
    }
    if ($includenograde) {
        $grades[0] = get_string('nograde');
    }
    for ($i=100; $i>=1; $i--) {
        $grades[$i] = $i;
    }
    $output .= html_writer::select($grades, $name, $current, false);

    $linkobject = '<span class="helplink">' . $OUTPUT->pix_icon('help', $strscales) . '</span>';
    $link = new moodle_url('/course/scales.php', array('id' => $courseid, 'list' => 1));
    $action = new popup_action('click', $link, 'ratingscales', array('height' => 400, 'width' => 500));
    $output .= $OUTPUT->action_link($link, $linkobject, $action, array('title' => $strscales));

    if ($return) {
        return $output;
    } else {
        echo $output;
    }
}

/**
 * Resets specified user's password and send the new password to the user via email.
 *
 * @param stdClass $user A {@link $USER} object
 * @return bool Returns true if mail was sent OK and false if there was an error.
 * @see setnew_password_and_mail()
 * @deprecated Since Moodle 4.5
 * @todo MDL-82646 Final deprecation in Moodle 6.0.
 */
#[\core\attribute\deprecated(
    since: '4.5',
    mdl: 'MDL-64148',
    replacement: 'setnew_password_and_mail()',
    reason: 'It is no longer used',
)]
function reset_password_and_mail($user) {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
    global $CFG;

    $site  = get_site();
    $supportuser = core_user::get_support_user();

    $userauth = get_auth_plugin($user->auth);
    if (!$userauth->can_reset_password() or !is_enabled_auth($user->auth)) {
        trigger_error("Attempt to reset user password for user $user->username with Auth $user->auth.");
        return false;
    }

    $newpassword = generate_password();

    if (!$userauth->user_update_password($user, $newpassword)) {
        throw new \moodle_exception("cannotsetpassword");
    }

    $a = new stdClass();
    $a->firstname   = $user->firstname;
    $a->lastname    = $user->lastname;
    $a->sitename    = format_string($site->fullname);
    $a->username    = $user->username;
    $a->newpassword = $newpassword;
    $a->link        = $CFG->wwwroot .'/login/change_password.php';
    $a->signoff     = generate_email_signoff();

    $message = get_string('newpasswordtext', '', $a);

    $subject  = format_string($site->fullname) .': '. get_string('changedpassword');

    unset_user_preference('create_password', $user); // Prevent cron from generating the password.

    // Directly email rather than using the messaging system to ensure its not routed to a popup or jabber.
    return email_to_user($user, $supportuser, $subject, $message);
}

/**
 * @deprecated Since Moodle 4.0 MDL-71175. Please use plagiarism_get_links() or plugin specific functions..
 */
#[\core\attribute\deprecated(
    replacement: 'plagiarism_get_links',
    since: '4.0',
    mdl: 'MDL-71175',
    final: true,
)]
function plagiarism_get_file_results(): void {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated Since Moodle 4.0 - Please use {plugin name}_before_standard_top_of_body_html instead.
 */
#[\core\attribute\deprecated(
    replacement: '{plugin name}_before_standard_top_of_body_html',
    since: '4.0',
    mdl: 'MDL-71175',
    final: true,
)]
function plagiarism_update_status(): void {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}
