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
 * Data generator class
 *
 * @package    repository_onedrive
 * @category   test
 * @copyright  2024 Daniel Ziegenberg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_onedrive_generator extends testing_repository_generator {

    /**
     * Fill in type record defaults.
     *
     * @param array $record
     * @return array
     */
    public function prepare_type_record(array $record): array{
        $record = parent::prepare_type_record($record);
        if (!isset($record['issuerid'])) {
            $record['issuerid'] = '99';
        }
        if (!isset($record['defaultreturntype'])) {
            $record['defaultreturntype'] = 'defaultreturntype';
        }
        if (!isset($record['supportedreturntypes'])) {
            $record['supportedreturntypes'] = 'supportedreturntypes';
        }
        return $record;
    }
}
