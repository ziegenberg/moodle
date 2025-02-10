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
 * @package    core_calendar
 * @copyright  2025 Daniel Ziegenberg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @deprecated since Moodle 4.3 MDL-79313
 */
#[\core\attribute\deprecated(since: '4.3', mdl: 'MDL-79313', final: true)]
function calendar_top_controls() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since Moodle 4.3 MDL-79432
 */
#[\core\attribute\deprecated(since: '4.3', mdl: 'MDL-79432', final: true)]
function calendar_get_link_previous() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}

/**
 * @deprecated since Moodle 4.3 MDL-79432
 */
#[\core\attribute\deprecated(since: '4.3', mdl: 'MDL-79432', final: true)]
function calendar_get_link_next() {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}
