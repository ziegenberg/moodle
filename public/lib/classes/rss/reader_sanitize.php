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

namespace core\rss;

use SimplePie\SimplePie;
use SimplePie\Sanitize;

/**
 * Customised feed sanitisation using Moodle's HTMLPurifier pipeline.
 *
 * @package   core
 * @copyright 2009 Dan Poltawski <talktodan@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reader_sanitize extends Sanitize {

    /**
     * Sanitise feed content, running any HTML through Moodle's HTMLPurifier.
     *
     * @param string $data The data to sanitise
     * @param int $type The SimplePie construct type
     * @param string $base Base URL to resolve relative links against
     * @return string The sanitised data
     */
    public function sanitize($data, $type, $base = '') {
        $data = trim($data);

        if ($data === '') {
            return '';
        }

        if ($type & SimplePie::CONSTRUCT_BASE64) {
            $data = base64_decode($data);
        }

        if ($type & SimplePie::CONSTRUCT_MAYBE_HTML) {
            if (preg_match('/(&(#(x[0-9a-fA-F]+|[0-9]+)|[a-zA-Z0-9]+)|<\/[A-Za-z][^\x09\x0A\x0B\x0C\x0D\x20\x2F\x3E]*'
                    . SimplePie::PCRE_HTML_ATTRIBUTE . '>)/', $data)) {
                $type |= SimplePie::CONSTRUCT_HTML;
            } else {
                $type |= SimplePie::CONSTRUCT_TEXT;
            }
        }

        if ($type & SimplePie::CONSTRUCT_IRI) {
            $absolute = $this->registry->call('Misc', 'absolutize_url', array($data, $base));
            if ($absolute !== false) {
                $data = $absolute;
            }
            $data = clean_param($data, PARAM_URL);
        }

        if ($type & (SimplePie::CONSTRUCT_TEXT | SimplePie::CONSTRUCT_IRI)) {
            $data = htmlspecialchars($data, ENT_COMPAT, 'UTF-8');
        }

        $data = purify_html($data);

        if ($this->remove_div) {
            $data = preg_replace('/^<div' . SimplePie::PCRE_XML_ATTRIBUTE . '>/', '', $data);
            $data = preg_replace('/<\/div>$/', '', $data);
        } else {
            $data = preg_replace('/^<div' . SimplePie::PCRE_XML_ATTRIBUTE . '>/', '<div>', $data);
        }

        if ($this->output_encoding !== 'UTF-8') {
            \core_text::convert($data, 'UTF-8', $this->output_encoding);
        }

        return $data;
    }
}
