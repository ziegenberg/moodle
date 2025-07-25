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

use core\exception\coding_exception;
use core\scss\moodle_importer;

/**
 * Moodle SCSS compiler class.
 *
 * @package    core
 * @copyright  2016 Frédéric Massart
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class compiler {
    /** @var string The path to the SCSS file. */
    protected $scssfile;

    /** @var array Bits of SCSS content to prepend. */
    protected $scssprepend = [];

    /** @var array Bits of SCSS content. */
    protected $scsscontent = [];

    /** @var bool */
    protected $sasscavailable = false;

    /** @var \ScssPhp\ScssPhp\Compiler PHP SCSS Compiler */
    protected $compiler = null;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->compiler = new \ScssPhp\ScssPhp\Compiler();
        $this->compiler->addImporter(new moodle_importer());
    }

    /**
     * Add variables.
     *
     * @param array $scss Associative array of variables and their values.
     * @return void
     */
    public function add_variables(array $variables) {
        $this->compiler->addVariables($variables);
    }

    /**
     * Append raw SCSS to what's to compile.
     *
     * @param string $scss SCSS code.
     * @return void
     */
    public function append_raw_scss($scss) {
        $this->scsscontent[] = $scss;
    }

    /**
     * Prepend raw SCSS to what's to compile.
     *
     * @param string $scss SCSS code.
     * @return void
     */
    public function prepend_raw_scss($scss) {
        $this->scssprepend[] = $scss;
    }

    /**
     * Set the file to compile from.
     *
     * The purpose of this method is to provide a way to import the
     * content of a file without messing with the import directories.
     *
     * @param string $filepath The path to the file.
     * @return void
     */
    public function set_file($filepath) {
        $this->scssfile = $filepath;
        $this->compiler->setImportPaths(dirname($filepath));
    }

    /**
     * Set import paths.
     *
     * In prior days, \core_scss extended \ScssPhp\ScssPhp\Compiler
     * and this method was inherited so we have to replicate it here.
     * We also cannot change the name and we need the phpcs:ignore comment.
     *
     * @param string|array $path
     */
    public function setImportPaths($path): void { // phpcs:ignore moodle.NamingConventions.ValidFunctionName.LowercaseMethod
        $this->compiler->setImportPaths($path);
    }

    /**
     * Enable/disable source maps
     *
     * In prior days, \core_scss extended \ScssPhp\ScssPhp\Compiler
     * and this method was inherited so we have to replicate it here.
     * We also cannot change the name and we need the phpcs:ignore comment.
     *
     * @param int $sourcemap
     */
    public function setSourceMap(int $sourcemap): void { // phpcs:ignore moodle.NamingConventions.ValidFunctionName.LowercaseMethod
        $this->compiler->setSourceMap($sourcemap);
    }

    /**
     * Set source map options
     *
     * In prior days, \core_scss extended \ScssPhp\ScssPhp\Compiler
     * and this method was inherited so we have to replicate it here.
     * We also cannot change the name and we need the phpcs:ignore comment.
     *
     * @param array $sourcemapoptions
     */
    public function setSourceMapOptions(array $sourcemapoptions): void { // phpcs:ignore moodle.NamingConventions.ValidFunctionName.LowercaseMethod
        $this->compiler->setSourceMapOptions($sourcemapoptions);
    }

    /**
     * Compiles to CSS.
     *
     * @return string
     */
    public function to_css() {
        $content = implode(';', $this->scssprepend);
        if (!empty($this->scssfile)) {
            $content .= file_get_contents($this->scssfile);
        }
        $content .= implode(';', $this->scsscontent);
        return $this->compile($content);
    }

    /**
     * Compile scss.
     *
     * Overrides ScssPHP's implementation, using the SassC compiler if it is available.
     *
     * @param string $code SCSS to compile.
     * @param string $path Path to SCSS to compile.
     *
     * @return string The compiled CSS.
     */
    public function compile($code, $path = null): string {
        global $CFG;

        $pathtosassc = trim($CFG->pathtosassc ?? '');

        if (!empty($pathtosassc) && is_executable($pathtosassc) && !is_dir($pathtosassc)) {
            $process = proc_open(
                $pathtosassc . ' -I' . implode(':', [dirname($this->scssfile)]) . ' -s',
                [
                    ['pipe', 'r'], // Set the process stdin pipe to read mode.
                    ['pipe', 'w'], // Set the process stdout pipe to write mode.
                    ['pipe', 'w'], // Set the process stderr pipe to write mode.
                ],
                $pipes // Pipes become available in $pipes (pass by reference).
            );
            if (is_resource($process)) {
                fwrite($pipes[0], $code); // Write the raw scss to the sassc process stdin.
                fclose($pipes[0]);

                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);

                fclose($pipes[1]);
                fclose($pipes[2]);

                // The proc_close function returns the process exit status. Anything other than 0 is bad.
                if (proc_close($process) !== 0 || $stdout === false) {
                    throw new coding_exception($stderr);
                }

                // Compiled CSS code will be available from stdout.
                return $stdout;
            }
        }

        return $this->compiler->compileString($code)->getCss();
    }
}

// Alias this class to the old name.
// This file will be autoloaded by the legacyclasses autoload system.
// In future all uses of this class will be corrected and the legacy references will be removed.
class_alias(compiler::class, \core_scss::class);
