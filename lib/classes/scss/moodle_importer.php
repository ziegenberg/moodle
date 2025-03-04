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

namespace core\scss;

use core\component;
use League\Uri\Contracts\UriInterface;
use ScssPhp\ScssPhp\Importer\Importer;
use ScssPhp\ScssPhp\Importer\ImportUtil;
use ScssPhp\ScssPhp\Importer\ImporterResult;
use ScssPhp\ScssPhp\Importer\FilesystemImporter;
use ScssPhp\ScssPhp\Util\Path;

/**
 * Class importer
 *
 * @package    core
 * @copyright  2025 Daniel Ziegenberg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_importer extends Importer {

    /** @var Importer */
    private readonly Importer $filesystemimporter;

    /**
     * The path relative to which this importer looks for files.
     *
     * If this is `null`, this importer will _only_ load absolute `file:` URLs
     * and URLs relative to the current file.
     */
    private readonly ?string $loadpath;

    /**
     * Undocumented function
     */
    public function __construct(?string $loadpath)
    {
        $this->filesystemimporter = new FilesystemImporter(null);
        $this->loadpath = $loadpath !== null ? Path::absolute($loadpath) : null;
    }

    /**
     * Undocumented function
     *
     * @param UriInterface $url
     * @return UriInterface|null
     */
    public function canonicalize(UriInterface $url): ?UriInterface {
        if ($url->getScheme() === 'file') {
            $resolved = ImportUtil::resolveImportPath(Path::fromUri($url));
        }

        if ($resolved === null) {
            return null;
        }

        return Path::toUri(Path::canonicalize($resolved));
    }

    /**
     * Undocumented function
     *
     * @param UriInterface $url
     * @return ImporterResult|null
     */
    public function load(UriInterface $url): ?ImporterResult {
        return $this->filesystemimporter->load($url);
    }

    /**
     * Provides a human-readable description of the importer.
     *
     */
    public function __toString(): string {
        return 'moodle_importer';
    }

    /**
     * Is the given file valid for import ?
     *
     * @param $path
     * @return bool
     */
    protected function is_valid_file($path) {
        global $CFG;

        $realpath = realpath($path);
        if ($realpath === false) {
            return false;
        }

        // Additional theme directory.
        $addthemedirectory = component::get_plugin_types()['theme'];
        $addrealroot = realpath($addthemedirectory);

        // Original theme directory.
        $themedirectory = $CFG->dirroot . "/theme";
        $realroot = realpath($themedirectory);

        // File should end in .scss and must be in sites theme directory, else ignore it.
        $pathvalid = substr($path, -5) === '.scss';
        $pathvalid = $pathvalid && (strpos($realpath, $realroot) === 0 || strpos($realpath, $addrealroot) === 0);
        return $pathvalid;
    }
}
