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
 * This plugin is used to access s3 files
 *
 * @since Moodle 2.0
 * @package    repository_s3
 * @copyright  2010 Dongsheng Cai {@link http://dongsheng.org}
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use Aws\Exception\AwsException;
use core\exception\moodle_exception;
use repository_s3\s3_client;
use repository_s3\s3_client_factory;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/repository/lib.php');

/**
 * This is a repository class used to browse Amazon S3 content.
 *
 * @since Moodle 2.0
 * @package    repository_s3
 * @copyright  2009 Dongsheng Cai {@link http://dongsheng.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_s3 extends repository {
    /** @var s3_client The S3 client wrapper used to communicate with Amazon S3. */
    private s3_client $s3;

    /**
     * Constructor
     * @param int $repositoryid
     * @param object $context
     * @param array $options
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = []) {
        parent::__construct($repositoryid, $context, $options);

        $accesskey = get_config('s3', 'access_key');
        $secretkey = get_config('s3', 'secret_key');
        $region = get_config('s3', 'api_region');
        if (empty($region)) {
            $region = 'us-east-1';
        }
        $endpoint = get_config('s3', 'endpoint');
        $usepathstyle = !empty(get_config('s3', 'use_path_style'));

        $this->s3 = new s3_client(
            s3_client_factory::create_client($accesskey, $secretkey, $region, $endpoint, $usepathstyle)
        );
    }

    /**
     * Extracts the Bucket and URI from the path
     *
     * @param string $path path in this format 'bucket/path/to/folder/and/file'
     * @return array including bucket and uri
     */
    protected function explode_path(string $path): array {
        $parts = explode('/', $path, 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * Get S3 file list
     *
     * @param string $path
     * @return array The file list and options
     */
    public function get_listing($path = '', $page = ''): array {
        global $OUTPUT;

        if (empty(get_config('s3', 'access_key'))) {
            throw new moodle_exception('needaccesskey', 'repository_s3');
        }

        $list = [
            'list' => [],
            'path' => $this->process_breadcrumbs($path),
            'manage' => false,
            'dynload' => true,
            'nologin' => true,
            'nosearch' => true,
        ];

        if ($path === '') {
            // Only listing buckets.
            $buckets = $this->get_buckets();
            foreach ($buckets as $bucket) {
                $list['list'][] = [
                    'title' => $bucket,
                    'children' => [],
                    'thumbnail' => $OUTPUT->image_url(file_folder_icon())->out(false),
                    'path' => $bucket,
                ];
            }
            return $list;
        }

        [$bucket, $uri] = $this->explode_path($path);
        $listing = $this->list_objects($bucket, $uri);

        foreach ($listing['folders'] as $prefix) {
            $title = rtrim($prefix, '/');
            if ($uri !== '') {
                $title = substr($title, strlen($uri));
            }
            $list['list'][] = [
                'title' => $title,
                'children' => [],
                'thumbnail' => $OUTPUT->image_url(file_folder_icon())->out(false),
                'path' => $bucket . '/' . $prefix,
            ];
        }

        foreach ($listing['objects'] as $object) {
            $title = basename($object['key']);
            $list['list'][] = [
                'title' => $title,
                'size' => $object['size'],
                'datemodified' => $object['lastmodified'],
                'source' => $bucket . '/' . $object['key'],
                'thumbnail' => $OUTPUT->image_url(file_extension_icon($title))->out(false),
            ];
        }

        return $list;
    }

    /**
     * Get the list of buckets accessible with the configured credentials.
     *
     * @return string[] The bucket names.
     */
    private function get_buckets(): array {
        try {
            return $this->s3->get_buckets();
        } catch (AwsException $e) {
            throw $this->get_communication_exception($e);
        }
    }

    /**
     * List the objects and folders (common prefixes) in a bucket below the given prefix.
     *
     * @param string $bucket The bucket name.
     * @param string $prefix The key prefix, without a leading slash.
     * @return array{
     *     objects: list<array{key: string, size: int, lastmodified: int}>,
     *     folders: list<string>,
     * }
     */
    private function list_objects(string $bucket, string $prefix): array {
        try {
            return $this->s3->list_objects($bucket, $prefix);
        } catch (AwsException $e) {
            throw $this->get_communication_exception($e);
        }
    }

    /**
     * Download S3 files to moodle
     *
     * @param string $url
     * @param string $filename The file path in moodle
     * @return array The local stored path
     */
    public function get_file($url, $filename = ''): array {
        [$bucket, $uri] = $this->explode_path($url);
        $path = $this->prepare_file($filename);
        try {
            $this->s3->get_object($bucket, $uri, $path);
        } catch (AwsException $e) {
            throw $this->get_communication_exception($e);
        }
        return ['path' => $path];
    }

    /**
     * Return the source information
     *
     * @param string $source
     * @return string
     */
    public function get_file_source_info($source) {
        return 'Amazon S3: ' . $source;
    }

    /**
     * S3 doesn't require login
     *
     * @return bool
     */
    public function check_login() {
        return true;
    }

    /**
     * S3 doesn't provide search
     *
     * @return bool
     */
    public function global_search() {
        return false;
    }

    /**
     * Return names of the general options.
     *
     * @return array
     */
    public static function get_type_option_names(): array {
        return ['access_key', 'secret_key', 'api_region', 'endpoint', 'use_path_style', 'pluginname'];
    }

    /**
     * Add Plugin settings input to Moodle form.
     *
     * @param moodleform $mform
     * @param string $classname
     */
    public static function type_config_form($mform, $classname = 'repository') {
        global $CFG;

        parent::type_config_form($mform, $classname);
        $strrequired = get_string('required');

        $mform->addElement('text', 'access_key', get_string('access_key', 'repository_s3'));
        $mform->setType('access_key', PARAM_RAW_TRIMMED);
        $mform->addRule('access_key', $strrequired, 'required', null, 'client');

        $mform->addElement('passwordunmask', 'secret_key', get_string('secret_key', 'repository_s3'));
        $mform->setType('secret_key', PARAM_RAW_TRIMMED);
        $mform->addRule('secret_key', $strrequired, 'required', null, 'client');

        $mform->addElement('select', 'api_region', get_string('api_region', 'repository_s3'), self::get_region_options());
        $mform->setDefault('api_region', 'us-east-1');

        $mform->addElement('text', 'endpoint', get_string('endpoint', 'repository_s3'));
        $mform->setType('endpoint', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('endpoint', 'endpoint', 'repository_s3');

        $mform->addElement('advcheckbox', 'use_path_style', get_string('use_path_style', 'repository_s3'));
        $mform->setType('use_path_style', PARAM_BOOL);
        $mform->addHelpButton('use_path_style', 'use_path_style', 'repository_s3');
        $mform->hideIf('use_path_style', 'endpoint', 'eq', '');
    }

    /**
     * Get the list of AWS regions, populated from the data file shipped with the AWS SDK.
     *
     * @return array<string, string> The region names as keys, with their descriptions as values.
     */
    private static function get_region_options(): array {
        global $CFG;

        // We do require() not require_once() here, as the file returns a value and we may need to get
        // this value more than once.
        $all = require($CFG->dirroot . '/lib/aws-sdk/src/data/endpoints.json.php');
        $regions = $all['partitions'][0]['regions'];

        $options = [];
        foreach ($regions as $key => $value) {
            $options[$key] = $key . ' - ' . $value['description'];
        }
        return $options;
    }

    /**
     * Map the legacy AWS endpoint setting value to an AWS region name.
     *
     * @param string $endpoint The legacy endpoint value, e.g. 's3-us-west-2.amazonaws.com'.
     * @return string|null The region name, or null when the value is not a known AWS endpoint.
     */
    public static function endpoint_to_region(string $endpoint): ?string {
        $map = [
            's3.amazonaws.com' => 'us-east-1',
            's3-external-1.amazonaws.com' => 'us-east-1',
            's3-us-west-2.amazonaws.com' => 'us-west-2',
            's3-us-west-1.amazonaws.com' => 'us-west-1',
            's3-eu-west-1.amazonaws.com' => 'eu-west-1',
            's3.eu-central-1.amazonaws.com' => 'eu-central-1',
            's3-eu-central-1.amazonaws.com' => 'eu-central-1',
            's3-ap-southeast-1.amazonaws.com' => 'ap-southeast-1',
            's3-ap-southeast-2.amazonaws.com' => 'ap-southeast-2',
            's3-ap-northeast-1.amazonaws.com' => 'ap-northeast-1',
            's3-sa-east-1.amazonaws.com' => 'sa-east-1',
        ];
        return $map[$endpoint] ?? null;
    }

    /**
     * Process the breadcrumbs for the given path.
     *
     * @param string $path The path to process for breadcrumbs.
     * @return array An array of breadcrumbs generated from the path.
     */
    private function process_breadcrumbs($path): array {
        $breadcrumbs = [
            [
                'path' => '',
                'name' => get_string('pluginname', 'repository_s3'),
            ],
        ];

        $path = rtrim($path, '/');
        $pathtodate = '';
        foreach (explode('/', $path) as $directory) {
            if ($directory === '') {
                continue;
            }
            $pathtodate .= '/' . $directory;
            $breadcrumbs[] = [
                'path' => $pathtodate,
                'name' => $directory,
            ];
        }

        return $breadcrumbs;
    }

    /**
     * S3 plugins supports references and internal files
     *
     * @return int
     */
    public function supported_returntypes() {
        return FILE_INTERNAL | FILE_REFERENCE;
    }

    /**
     * Return human readable reference information
     *
     * @param string $reference value of DB field files_reference.reference
     * @param int $filestatus status of the file, 0 - ok, 666 - source missing
     * @return string
     */
    public function get_reference_details($reference, $filestatus = 0) {
        $details = $this->get_name() . ': ' . $reference;
        if ($filestatus) {
            return get_string('lostsource', 'repository', $details);
        } else {
            return $details;
        }
    }

    /**
     * Synchronise the file metadata of a reference, and its content when required.
     *
     * The content is only downloaded for images, so that Moodle can generate thumbnails.
     * For all other files only the size and modification time are updated, the content is
     * served directly from S3 by {@see send_file()}.
     *
     * @param stored_file $file
     * @return bool
     */
    public function sync_reference(stored_file $file) {
        if ($file->get_referencelastsync() + 60 > time()) {
            // Do not synchronise more often than once a minute.
            return false;
        }
        static $issyncing = false;
        if ($issyncing) {
            // Avoid infinite recursion when calling $file->get_filesize() and get_contenthash().
            return false;
        }

        [$bucket, $uri] = $this->explode_path($file->get_reference());

        try {
            $info = $this->s3->get_object_info($bucket, $uri);
        } catch (AwsException $e) {
            $file->set_missingsource();
            return true;
        }

        $issyncing = true;
        if (file_extension_in_typegroup($file->get_filename(), 'web_image')) {
            $path = $this->prepare_file($file->get_filename());
            try {
                $this->s3->get_object($bucket, $uri, $path);
            } catch (AwsException $e) {
                $issyncing = false;
                $file->set_missingsource();
                return true;
            }
            $contenthash = file_storage::hash_from_path($path);
            if ($file->get_contenthash() !== $contenthash) {
                // Copy file into moodle filepool (used to generate an image thumbnail).
                $file->set_timemodified($info['lastmodified']);
                $file->set_synchronised_content_from_file($path);
                $issyncing = false;
                return true;
            }
            $file->set_synchronized($contenthash, $info['size'], 0, $info['lastmodified']);
        } else {
            // Update only the file size so the file will NOT be copied into moodle filepool.
            if ($file->get_contenthash() === '' || $file->compare_to_string('')) {
                // The file has never been synchronised or its content is empty, mark it as having no local content.
                $contenthash = file_storage::hash_from_string('');
            } else {
                // The file content was synchronised before, leave it untouched.
                $contenthash = null;
            }
            $file->set_synchronized($contenthash, $info['size'], 0, $info['lastmodified']);
        }
        $issyncing = false;
        return true;
    }

    /**
     * Repository method to serve the referenced file
     *
     * @see send_stored_file
     *
     * @param stored_file $storedfile the file that contains the reference
     * @param int $lifetime Number of seconds before the file should expire from caches (null means $CFG->filelifetime)
     * @param int $filter 0 (default)=no filtering, 1=all files, 2=html files only
     * @param bool $forcedownload If true (default false), forces download of file rather than view in browser/plugin
     * @param array $options additional options affecting the file serving
     */
    public function send_file($storedfile, $lifetime = null, $filter = 0, $forcedownload = false, ?array $options = null) {
        [$bucket, $uri] = $this->explode_path($storedfile->get_reference());
        $path = $this->prepare_file($storedfile->get_filename());
        try {
            $this->s3->get_object($bucket, $uri, $path);
        } catch (AwsException $e) {
            send_file_not_found();
            return;
        }

        $filename = $storedfile->get_filename();
        if ($options && isset($options['filename'])) {
            $filename = $options['filename'];
        }
        $dontdie = ($options && isset($options['dontdie']));
        // If the file is linked to the original then we should not cache it.
        send_file($path, $filename, 0, $filter, false, $forcedownload, '', $dontdie);
    }

    /**
     * Build a repository exception for a failed communication with Amazon S3.
     *
     * @param AwsException $e The underlying AWS SDK exception.
     * @return moodle_exception
     */
    private function get_communication_exception(AwsException $e): moodle_exception {
        return new moodle_exception(
            'errorwhilecommunicatingwith',
            'repository',
            '',
            $this->get_name(),
            $e->getMessage()
        );
    }

    /**
     * Is this repository accessing private data?
     *
     * @return bool
     */
    public function contains_private_data() {
        return false;
    }
}
