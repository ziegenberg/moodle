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

require_once($CFG->dirroot.'/lib/gradelib.php');
require_once($CFG->dirroot.'/grade/lib.php');
require_once($CFG->dirroot.'/grade/export/grade_export_form.php');

/**
 * Base export class
 */
abstract class grade_export {

    /** @var int Value to state nothing is being exported. */
    protected const EXPORT_SELECT_NONE = -1;

    public $plugin; // plgin name - must be filled in subclasses!

    public $grade_items; // list of all course grade items
    public $groupid;     // groupid, 0 means all groups
    public $course;      // course object
    public $columns;     // array of grade_items selected for export

    public $export_letters;  // export letters
    public $export_feedback; // export feedback
    public $userkey;         // export using private user key

    public $updatedgradesonly; // only export updated grades

    /**
     *  Grade display type (real, percentages or letter).
     *
     *  This attribute is an integer for XML file export. Otherwise is an array for all other formats (ODS, XLS and TXT).
     *
     *  @var $displaytype Grade display type constant (1, 2 or 3) or an array of display types where the key is the name
     *                    and the value is the grade display type constant or 0 for unchecked display types.
     * @access public.
     */
    public $displaytype;
    public $decimalpoints; // number of decimal points for exports
    public $onlyactive; // only include users with an active enrolment
    public $usercustomfields; // include users custom fields

    /**
     * @deprecated since Moodle 2.8
     * @var $previewrows Number of rows in preview.
     */
    public $previewrows;

    /**
     * Constructor should set up all the private variables ready to be pulled.
     *
     * This constructor used to accept the individual parameters as separate arguments, in
     * 2.8 this was simplified to just accept the data from the moodle form.
     *
     * @access public
     * @param object $course
     * @param int $groupid
     * @param stdClass|null $formdata
     * @note Exporting as letters will lead to data loss if that exported set it re-imported.
     */
    public function __construct($course, $groupid, $formdata) {
        $this->course = $course;
        $this->groupid = $groupid;

        $this->grade_items = grade_item::fetch_all(array('courseid'=>$this->course->id));

        $this->process_form($formdata);
    }

    /**
     * @deprecated since 2.8 MDL-46548. Instead call the shortened constructor which accepts the data
     */
    #[\core\attribute\deprecated(null, since: '2.8', mdl: 'MDL-46548', final: true)]
    protected function deprecated_constructor($course,
                                              $groupid=0,
                                              $itemlist='',
                                              $export_feedback=false,
                                              $updatedgradesonly = false,
                                              $displaytype = GRADE_DISPLAY_TYPE_REAL,
                                              $decimalpoints = 2,
                                              $onlyactive = false,
                                              $usercustomfields = false) {
        \core\deprecation::emit_deprecation_if_present([self::class, __FUNCTION__]);
    }

    /**
     * Init object based using data from form
     * @param object $formdata
     */
    function process_form($formdata) {
        global $USER;

        $this->columns = array();
        if (!empty($formdata->itemids)) {
            // Check that user selected something.
            if ($formdata->itemids != self::EXPORT_SELECT_NONE) {
                foreach ($formdata->itemids as $itemid=>$selected) {
                    if ($selected and array_key_exists($itemid, $this->grade_items)) {
                        $this->columns[$itemid] =& $this->grade_items[$itemid];
                    }
                }
            }
        } else {
            foreach ($this->grade_items as $itemid=>$unused) {
                $this->columns[$itemid] =& $this->grade_items[$itemid];
            }
        }

        if (isset($formdata->key)) {
            if ($formdata->key == 1 && isset($formdata->iprestriction) && isset($formdata->validuntil)) {
                // Create a new key
                $formdata->key = create_user_key('grade/export', $USER->id, $this->course->id, $formdata->iprestriction, $formdata->validuntil);
            }
            $this->userkey = $formdata->key;
        }

        if (isset($formdata->decimals)) {
            $this->decimalpoints = $formdata->decimals;
        }

        if (isset($formdata->export_letters)) {
            $this->export_letters = $formdata->export_letters;
        }

        if (isset($formdata->export_feedback)) {
            $this->export_feedback = $formdata->export_feedback;
        }

        if (isset($formdata->export_onlyactive)) {
            $this->onlyactive = $formdata->export_onlyactive;
        }

        if (isset($formdata->previewrows)) {
            $this->previewrows = $formdata->previewrows;
        }

        if (isset($formdata->display)) {
            $this->displaytype = $formdata->display;

            // Used by grade exports which accept multiple display types.
            // If the checkbox value is 0 (unchecked) then remove it.
            if (is_array($formdata->display)) {
                $this->displaytype = array_filter($formdata->display);
            }
        }

        if (isset($formdata->updatedgradesonly)) {
            $this->updatedgradesonly = $formdata->updatedgradesonly;
        }
    }

    /**
     * Update exported field in grade_grades table
     * @return boolean
     */
    public function track_exports() {
        global $CFG;

        /// Whether this plugin is entitled to update export time
        if ($expplugins = explode(",", $CFG->gradeexport)) {
            if (in_array($this->plugin, $expplugins)) {
                return true;
            } else {
                return false;
          }
        } else {
            return false;
        }
    }

    /**
     * Returns string representation of final grade
     * @param object $grade instance of grade_grade class
     * @param integer $gradedisplayconst grade display type constant.
     * @return string
     */
    public function format_grade($grade, $gradedisplayconst = null) {
        $displaytype = $this->displaytype;
        if (is_array($this->displaytype) && !is_null($gradedisplayconst)) {
            $displaytype = $gradedisplayconst;
        }

        $gradeitem = $this->grade_items[$grade->itemid];

        // We are going to store the min and max so that we can "reset" the grade_item for later.
        $grademax = $gradeitem->grademax;
        $grademin = $gradeitem->grademin;

        // Updating grade_item with this grade_grades min and max.
        $gradeitem->grademax = $grade->get_grade_max();
        $gradeitem->grademin = $grade->get_grade_min();

        $formattedgrade = grade_format_gradevalue($grade->finalgrade, $gradeitem, false, $displaytype, $this->decimalpoints);

        // Resetting the grade item in case it is reused.
        $gradeitem->grademax = $grademax;
        $gradeitem->grademin = $grademin;

        return $formattedgrade;
    }

    /**
     * Returns the name of column in export
     * @param object $grade_item
     * @param boolean $feedback feedback colum
     * @param string $gradedisplayname grade display name.
     * @return string
     */
    public function format_column_name($grade_item, $feedback=false, $gradedisplayname = null) {
        $column = new stdClass();

        if ($grade_item->itemtype == 'mod') {
            $column->name = get_string('modulename', $grade_item->itemmodule).get_string('labelsep', 'langconfig').$grade_item->get_name();
        } else {
            $column->name = $grade_item->get_name(true);
        }

        // We can't have feedback and display type at the same time.
        $column->extra = ($feedback) ? get_string('feedback') : get_string($gradedisplayname, 'grades');

        return html_to_text(get_string('gradeexportcolumntype', 'grades', $column), 0, false);
    }

    /**
     * Returns formatted grade feedback
     * @param object $feedback object with properties feedback and feedbackformat
     * @param object $grade Grade object with grade properties
     * @return string
     */
    public function format_feedback($feedback, $grade = null) {
        $string = $feedback->feedback;
        if (!empty($grade)) {
            // Rewrite links to get the export working for 36, refer MDL-63488.
            $string = file_rewrite_pluginfile_urls(
                $feedback->feedback,
                'pluginfile.php',
                $grade->get_context()->id,
                GRADE_FILE_COMPONENT,
                GRADE_FEEDBACK_FILEAREA,
                $grade->id
            );
        }

        return strip_tags(format_text($string, $feedback->feedbackformat));
    }

    /**
     * Implemented by child class
     */
    abstract public function print_grades();

    /**
     * @deprecated since 2.8 MDL-46548. Previews are not useful on export.
     */
    #[\core\attribute\deprecated(null, since: '2.8', mdl: 'MDL-46548', final: true)]
    public function display_preview($require_user_idnumber=false) {
        \core\deprecation::emit_deprecation_if_present([self::class, __FUNCTION__]);
    }

    /**
     * Returns array of parameters used by dump.php and export.php.
     * @return array
     */
    public function get_export_params() {
        $itemids = array_keys($this->columns);
        $itemidsparam = implode(',', $itemids);
        if (empty($itemidsparam)) {
            $itemidsparam = self::EXPORT_SELECT_NONE;
        }

        // We have a single grade display type constant.
        if (!is_array($this->displaytype)) {
            $displaytypes = $this->displaytype;
        } else {
            // Implode the grade display types array as moodle_url function doesn't accept arrays.
            $displaytypes = implode(',', $this->displaytype);
        }

        if (!empty($this->updatedgradesonly)) {
            $updatedgradesonly = $this->updatedgradesonly;
        } else {
            $updatedgradesonly = 0;
        }
        $params = array('id'                => $this->course->id,
                        'groupid'           => $this->groupid,
                        'itemids'           => $itemidsparam,
                        'export_letters'    => $this->export_letters,
                        'export_feedback'   => $this->export_feedback,
                        'updatedgradesonly' => $updatedgradesonly,
                        'decimalpoints'     => $this->decimalpoints,
                        'export_onlyactive' => $this->onlyactive,
                        'usercustomfields'  => $this->usercustomfields,
                        'displaytype'       => $displaytypes,
                        'key'               => $this->userkey);

        return $params;
    }

    /**
     * @deprecated since 2.8 MDL-46548. Call get_export_url and set the action of the grade_export_form instead.
     */
    #[\core\attribute\deprecated(null, since: '2.8', mdl: 'MDL-46548', final: true)]
    public function print_continue() {
        \core\deprecation::emit_deprecation_if_present([self::class, __FUNCTION__]);
    }

    /**
     * Generate the export url.
     *
     * Get submitted form data and create the url to be used on the grade publish feature.
     *
     * @return moodle_url the url of grade publishing export.
     */
    public function get_export_url() {
        return new moodle_url('/grade/export/'.$this->plugin.'/dump.php', $this->get_export_params());
    }

    /**
     * Convert the grade display types parameter into the required array to grade exporting class.
     *
     * In order to export, the array key must be the display type name and the value must be the grade display type
     * constant.
     *
     * Note: Added support for combined display types constants like the (GRADE_DISPLAY_TYPE_PERCENTAGE_REAL) as
     *       the $CFG->grade_export_displaytype config is still used on 2.7 in case of missing displaytype url param.
     *       In these cases, the file will be exported with a column for each display type.
     *
     * @param string $displaytypes can be a single or multiple display type constants comma separated.
     * @return array $types
     */
    public static function convert_flat_displaytypes_to_array($displaytypes) {
        $types = array();

        // We have a single grade display type constant.
        if (is_int($displaytypes)) {
            $displaytype = clean_param($displaytypes, PARAM_INT);

            // Let's set a default value, will be replaced below by the grade display type constant.
            $display[$displaytype] = 1;
        } else {
            // Multiple grade display types constants.
            $display = array_flip(explode(',', $displaytypes));
        }

        // Now, create the array in the required format by grade exporting class.
        foreach ($display as $type => $value) {
            $type = clean_param($type, PARAM_INT);
            if ($type == GRADE_DISPLAY_TYPE_LETTER) {
                $types['letter'] = GRADE_DISPLAY_TYPE_LETTER;
            } else if ($type == GRADE_DISPLAY_TYPE_PERCENTAGE) {
                $types['percentage'] = GRADE_DISPLAY_TYPE_PERCENTAGE;
            } else if ($type == GRADE_DISPLAY_TYPE_REAL) {
                $types['real'] = GRADE_DISPLAY_TYPE_REAL;
            } else if ($type == GRADE_DISPLAY_TYPE_REAL_PERCENTAGE) {
                $types['real'] = GRADE_DISPLAY_TYPE_REAL;
                $types['percentage'] = GRADE_DISPLAY_TYPE_PERCENTAGE;
            } else if ($type == GRADE_DISPLAY_TYPE_REAL_LETTER) {
                $types['real'] = GRADE_DISPLAY_TYPE_REAL;
                $types['letter'] = GRADE_DISPLAY_TYPE_LETTER;
            } else if ($type == GRADE_DISPLAY_TYPE_LETTER_REAL) {
                $types['letter'] = GRADE_DISPLAY_TYPE_LETTER;
                $types['real'] = GRADE_DISPLAY_TYPE_REAL;
            } else if ($type == GRADE_DISPLAY_TYPE_LETTER_PERCENTAGE) {
                $types['letter'] = GRADE_DISPLAY_TYPE_LETTER;
                $types['percentage'] = GRADE_DISPLAY_TYPE_PERCENTAGE;
            } else if ($type == GRADE_DISPLAY_TYPE_PERCENTAGE_LETTER) {
                $types['percentage'] = GRADE_DISPLAY_TYPE_PERCENTAGE;
                $types['letter'] = GRADE_DISPLAY_TYPE_LETTER;
            } else if ($type == GRADE_DISPLAY_TYPE_PERCENTAGE_REAL) {
                $types['percentage'] = GRADE_DISPLAY_TYPE_PERCENTAGE;
                $types['real'] = GRADE_DISPLAY_TYPE_REAL;
            }
        }
        return $types;
    }

    /**
     * Convert the item ids parameter into the required array to grade exporting class.
     *
     * In order to export, the array key must be the grade item id and all values must be one.
     *
     * @param string $itemids can be a single item id or many item ids comma separated.
     * @return array $items correctly formatted array.
     */
    public static function convert_flat_itemids_to_array($itemids) {
        $items = array();

        // We just have one single item id.
        if (is_int($itemids)) {
            $itemid = clean_param($itemids, PARAM_INT);
            $items[$itemid] = 1;
        } else {
            // Few grade items.
            $items = array_flip(explode(',', $itemids));
            foreach ($items as $itemid => $value) {
                $itemid = clean_param($itemid, PARAM_INT);
                $items[$itemid] = 1;
            }
        }
        return $items;
    }

    /**
     * Create the html code of the grade publishing feature.
     *
     * @return string $output html code of the grade publishing.
     */
    public function get_grade_publishing_url() {
        $url = $this->get_export_url();
        $output =  html_writer::start_div();
        $output .= html_writer::tag('p', get_string('gradepublishinglink', 'grades', html_writer::link($url, $url)));
        $output .=  html_writer::end_div();
        return $output;
    }

    /**
     * Create a stdClass object from URL parameters to be used by grade_export class.
     *
     * @param int $id course id.
     * @param string $itemids grade items comma separated.
     * @param bool $exportfeedback export feedback option.
     * @param bool $onlyactive only enrolled active students.
     * @param string $displaytype grade display type constants comma separated.
     * @param int $decimalpoints grade decimal points.
     * @param null $updatedgradesonly recently updated grades only (Used by XML exporting only).
     * @param null $separator separator character: tab, comma, colon and semicolon (Used by TXT exporting only).
     *
     * @return stdClass $formdata
     */
    public static function export_bulk_export_data($id, $itemids, $exportfeedback, $onlyactive, $displaytype,
                                                   $decimalpoints, $updatedgradesonly = null, $separator = null) {

        $formdata = new \stdClass();
        $formdata->id = $id;
        $formdata->itemids = self::convert_flat_itemids_to_array($itemids);
        $formdata->exportfeedback = $exportfeedback;
        $formdata->export_onlyactive = $onlyactive;
        $formdata->display = self::convert_flat_displaytypes_to_array($displaytype);
        $formdata->decimals = $decimalpoints;

        if (!empty($updatedgradesonly)) {
            $formdata->updatedgradesonly = $updatedgradesonly;
        }

        if (!empty($separator)) {
            $formdata->separator = $separator;
        }

        return $formdata;
    }
}

/**
 * This class is used to update the exported field in grade_grades.
 * It does internal buffering to speedup the db operations.
 */
class grade_export_update_buffer {
    public $update_list;
    public $export_time;

    /**
     * Constructor - creates the buffer and initialises the time stamp
     */
    public function __construct() {
        $this->update_list = array();
        $this->export_time = time();
    }

    /**
     * Old syntax of class constructor. Deprecated in PHP7.
     *
     * @deprecated since Moodle 3.1
     */
    public function grade_export_update_buffer() {
        debugging('Use of class name as constructor is deprecated', DEBUG_DEVELOPER);
        self::__construct();
    }

    public function flush($buffersize) {
        global $CFG, $DB;

        if (count($this->update_list) > $buffersize) {
            list($usql, $params) = $DB->get_in_or_equal($this->update_list);
            $params = array_merge(array($this->export_time), $params);

            $sql = "UPDATE {grade_grades} SET exported = ? WHERE id $usql";
            $DB->execute($sql, $params);
            $this->update_list = array();
        }
    }

    /**
     * Track grade export status
     * @param object $grade_grade
     * @return string $status (unknow, new, regrade, nochange)
     */
    public function track($grade_grade) {

        if (empty($grade_grade->exported) or empty($grade_grade->timemodified)) {
            if (is_null($grade_grade->finalgrade)) {
                // grade does not exist yet
                $status = 'unknown';
            } else {
                $status = 'new';
                $this->update_list[] = $grade_grade->id;
            }

        } else if ($grade_grade->exported < $grade_grade->timemodified) {
            $status = 'regrade';
            $this->update_list[] = $grade_grade->id;

        } else if ($grade_grade->exported >= $grade_grade->timemodified) {
            $status = 'nochange';

        } else {
            // something is wrong?
            $status = 'unknown';
        }

        $this->flush(100);

        return $status;
    }

    /**
     * Flush and close the buffer.
     */
    public function close() {
        $this->flush(0);
    }
}

/**
 * Verify that there is a valid set of grades to export.
 * @param $courseid int The course being exported
 */
function export_verify_grades($courseid) {
    if (grade_needs_regrade_final_grades($courseid)) {
        throw new moodle_exception('gradesneedregrading', 'grades');
    }
}
