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

/**
 * List of deprecated theme_boost functions.
 *
 * @package   theme_boost
 * @copyright 2025 Daniel Ziegenberg
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @deprecated since 3.10 MDL-69117
 */
#[\core\attribute\deprecated(
    null,
    since: '3.10',
    reason: 'Required prefixes for Bootstrap are now in theme/boost/scss/moodle/prefixes.scss',
    mdl: 'MDL-69117',
    final: true
)]
function theme_boost_css_tree_post_processor($tree, $theme) {
    \core\deprecation::emit_deprecation_if_present(__FUNCTION__);
}
