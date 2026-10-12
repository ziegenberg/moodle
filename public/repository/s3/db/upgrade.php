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
 * Plugin upgrade steps are defined here.
 *
 * @package     repository_s3
 * @category    upgrade
 * @copyright   2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute repository_s3 upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_repository_s3_upgrade($oldversion) {
    global $CFG;

    if ($oldversion < 2026101300) {
        // The plugin lib.php is not loaded during upgrades, require it to use the repository class.
        require_once($CFG->dirroot . '/repository/s3/lib.php');

        // The standalone S3 client has been replaced by the AWS SDK which requires a region
        // instead of an endpoint. Migrate configured endpoints: known AWS endpoints map to a
        // region, anything else is kept as a custom endpoint for S3-compatible services.
        $endpoint = get_config('s3', 'endpoint');
        if ($endpoint !== false) {
            $region = repository_s3::endpoint_to_region($endpoint);
            if ($region !== null) {
                set_config('api_region', $region, 's3');
                unset_config('endpoint', 's3');
            } else {
                set_config('endpoint', \repository_s3\s3_client_factory::normalise_endpoint($endpoint), 's3');
                if (empty(get_config('s3', 'api_region'))) {
                    set_config('api_region', 'us-east-1', 's3');
                }
            }
        }
    }

    return true;
}
