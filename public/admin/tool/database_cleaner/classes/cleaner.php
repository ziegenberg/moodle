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

namespace tool_database_cleaner;

use stdClass;

/**
 * Orphan course module detection and deletion service.
 *
 * The cleaner is the single place that knows how to find orphan course modules
 * and how to remove them. It classifies every scanned course module into one of
 * three buckets:
 *
 *  - deletable:      a true orphan (instance non-zero but the instance row is
 *                     missing) whose activity table, course and section still
 *                     exist. These are the only course modules the tool deletes.
 *  - cannotverify:   the module's activity table is missing (a plugin removed
 *                     without a clean uninstall), so the orphan check cannot
 *                     run. Reported only, never deleted.
 *  - cannotclean:     a true orphan whose course or section is also missing, so
 *                     the surrounding cleanup would fail. Reported only, never
 *                     deleted.
 *
 * Deletion delegates to the core orphan-tolerant deletion entry point
 * (core_courseformat\formatactions::cm(...)->delete_orphan(...)), so the full
 * surrounding cleanup is performed without duplicating it here.
 *
 * @package    tool_database_cleaner
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleaner {

    /** Bucket: a true orphan that can be safely deleted. */
    public const BUCKET_DELETABLE = 'deletable';

    /** Bucket: the activity table is missing, so orphan status cannot be verified. */
    public const BUCKET_CANNOT_VERIFY = 'cannotverify';

    /** Bucket: a true orphan whose course or section is also missing. */
    public const BUCKET_CANNOT_CLEAN = 'cannotclean';

    /**
     * Detect orphan course modules and classify them into the three buckets.
     *
     * @return array Associative array keyed by bucket constant; each value is a
     *               list of stdClass records with id, course, section, module,
     *               modulename, instance, added and bucket.
     */
    public function find_orphans(): array {
        global $DB;

        $result = [
            self::BUCKET_DELETABLE => [],
            self::BUCKET_CANNOT_VERIFY => [],
            self::BUCKET_CANNOT_CLEAN => [],
        ];

        $modules = $DB->get_records('modules', null, 'name ASC', 'id, name');
        foreach ($modules as $module) {
            // Module names are always [a-z0-9_]+; guard the table name used in SQL.
            if (!preg_match('/^[a-z0-9_]+$/', $module->name)) {
                continue;
            }

            if (!$DB->get_manager()->table_exists($module->name)) {
                // The activity table is gone (plugin removed without uninstall): we
                // cannot tell whether these course modules are orphans. Report them.
                $cms = $DB->get_records('course_modules', ['module' => $module->id], '', 'id, course, section, module, instance, added');
                foreach ($cms as $cm) {
                    $result[self::BUCKET_CANNOT_VERIFY][] = $this->normalise($cm, $module->name, self::BUCKET_CANNOT_VERIFY);
                }
                continue;
            }

            // Find course modules for this module whose activity instance row is
            // missing (instance non-zero, no matching instance row). LEFT JOIN the
            // course and section so we can classify deletable vs cannot-clean in
            // one pass.
            $sql = "SELECT cm.id, cm.course, cm.section, cm.module, cm.instance, cm.added,
                           c.id AS courseexists, cs.id AS sectionexists
                      FROM {course_modules} cm
                 LEFT JOIN {" . $module->name . "} a ON a.id = cm.instance
                 LEFT JOIN {course} c ON c.id = cm.course
                 LEFT JOIN {course_sections} cs ON cs.id = cm.section
                     WHERE cm.module = :moduleid AND cm.instance <> 0 AND a.id IS NULL";
            $orphans = $DB->get_records_sql($sql, ['moduleid' => $module->id]);

            foreach ($orphans as $cm) {
                $bucket = (empty($cm->courseexists) || empty($cm->sectionexists))
                    ? self::BUCKET_CANNOT_CLEAN
                    : self::BUCKET_DELETABLE;
                $result[$bucket][] = $this->normalise($cm, $module->name, $bucket);
            }
        }

        return $result;
    }

    /**
     * Delete the given orphan course modules.
     *
 * Each course module is removed best-effort, delegating the surrounding
     * cleanup to the core orphan-tolerant deletion (cmactions::delete_orphan).
     * Because Moodle's delegated transactions are all-or-nothing (a nested
     * rollback performs no real database rollback), per-orphan transactions are
     * impossible; instead delete_orphan() verifies the section still exists before
     * any destructive work, so a cannot-clean orphan fails clean with no partial
     * state. A failure is recorded with its exception, the orphan is skipped, and
     * the batch continues; the returned summary reports the cleaned and failed
     * counts.
     *
     * Callers should only pass course modules that find_orphans() classified as
     * deletable. A course module that turns out to be uncleanable is skipped
     * cleanly and reported as failed rather than partially removed.
     *
     * @param array $cmids List of course module ids to remove.
     * @return stdClass Summary with cleaned (int[]), failed (string[] cmid => message),
     *                  cleanedcount (int) and failedcount (int).
     */
    public function delete_orphans(array $cmids): stdClass {
        global $DB;

        $result = new stdClass();
        $result->cleaned = [];
        $result->failed = [];

        foreach ($cmids as $cmid) {
            $cmid = (int)$cmid;
            $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id, course', IGNORE_MISSING);
            if (!$cm) {
                // Already gone; nothing to clean up.
                $result->failed[$cmid] = get_string('error_cm_not_found', 'tool_database_cleaner');
                continue;
            }
            // NOTE: Moodle's delegated transactions are all-or-nothing - a nested
            // rollback sets force_rollback and does NOT perform a real database
            // rollback (only the topmost level touches the DB). Per-orphan
            // isolation is therefore impossible. Instead each orphan is deleted
            // best-effort: delete_orphan() verifies the section still exists before
            // any destructive work, so an uncleanable orphan fails clean (no partial
            // state) and the batch continues. Successful deletions persist
            // immediately (auto-commit); a failure is recorded and skipped.
            try {
                \core_courseformat\formatactions::cm($cm->course)->delete_orphan($cmid);
                $result->cleaned[] = $cmid;
            } catch (\Throwable $e) {
                $result->failed[$cmid] = $e->getMessage();
                continue;
            }
        }

        $result->cleanedcount = count($result->cleaned);
        $result->failedcount = count($result->failed);
        return $result;
    }

    /**
     * Normalise a raw course module record into a consistent report row.
     *
     * @param stdClass $cm The raw record (must carry id, course, section, module, instance, added).
     * @param string $modulename The module name.
     * @param string $bucket The bucket the record was classified into.
     * @return stdClass
     */
    private function normalise(stdClass $cm, string $modulename, string $bucket): stdClass {
        $row = new stdClass();
        // Cast the integer columns: some database drivers (notably pgsql) return
        // integer columns as strings, and strict comparisons in callers/tests would
        // otherwise fail against the int ids the data generator produces.
        $row->id = (int)$cm->id;
        $row->course = (int)$cm->course;
        $row->section = (int)$cm->section;
        $row->module = (int)$cm->module;
        $row->modulename = $modulename;
        $row->instance = (int)$cm->instance;
        $row->added = (int)($cm->added ?? 0);
        $row->bucket = $bucket;
        return $row;
    }
}
