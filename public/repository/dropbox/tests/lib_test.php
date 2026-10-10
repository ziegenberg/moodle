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

namespace repository_dropbox;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/repository/lib.php');
require_once($CFG->dirroot . '/repository/dropbox/lib.php');

/**
 * Tests for the repository_dropbox class.
 *
 * @package     repository_dropbox
 * @copyright  2025 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends \advanced_testcase {

    /**
     * Data provider for the sync_reference tests.
     *
     * @return array[]
     */
    public static function sync_reference_provider(): array {
        return [
            'referencelastsync done recently' => [
                'storedfileargs' => [
                    'reference' => [
                        'path' => '/folder/testfile.png',
                        'url' => 'https://www.dropbox.com/s/abc123/testfile.png',
                    ],
                    'lastsyncrecent' => true,
                ],
                'httpresponses' => [],
                'expectsync' => 'none',
                'expectedrequestcount' => 0,
                'expectedmethods' => [],
                'expectedresult' => false,
            ],
            'reference without url' => [
                'storedfileargs' => [
                    'reference' => [
                        'path' => '/folder/testfile.png',
                    ],
                ],
                'httpresponses' => [],
                'expectsync' => 'none',
                'expectedrequestcount' => 0,
                'expectedmethods' => [],
                'expectedresult' => false,
            ],
            'image file synced successfully' => [
                'storedfileargs' => [
                    'reference' => [
                        'path' => '/folder/testfile.png',
                        'url' => 'https://www.dropbox.com/s/abc123/testfile.png',
                    ],
                ],
                'httpresponses' => [
                    new Response(200, [], 'image content'),
                ],
                'expectsync' => 'content',
                'expectedrequestcount' => 1,
                'expectedmethods' => ['GET'],
                'expectedresult' => true,
            ],
            'image download returns error status, head request provides the size' => [
                'storedfileargs' => [
                    'reference' => [
                        'path' => '/folder/testfile.png',
                        'url' => 'https://www.dropbox.com/s/abc123/testfile.png',
                    ],
                ],
                'httpresponses' => [
                    new Response(404),
                    new Response(200, ['Content-Length' => '1234']),
                ],
                'expectsync' => 'size',
                'expectedrequestcount' => 2,
                'expectedmethods' => ['GET', 'HEAD'],
                'expectedresult' => true,
            ],
            'image download fails, head request provides the size' => [
                'storedfileargs' => [
                    'reference' => [
                        'path' => '/folder/testfile.png',
                        'url' => 'https://www.dropbox.com/s/abc123/testfile.png',
                    ],
                ],
                'httpresponses' => [
                    new ConnectException(
                        'Connection refused',
                        new Request('GET', 'https://www.dropbox.com/s/abc123/testfile.png?dl=1'),
                    ),
                    new Response(200, ['Content-Length' => '1234']),
                ],
                'expectsync' => 'size',
                'expectedrequestcount' => 2,
                'expectedmethods' => ['GET', 'HEAD'],
                'expectedresult' => true,
            ],
            'image download fails, head request fails too' => [
                'storedfileargs' => [
                    'reference' => [
                        'path' => '/folder/testfile.png',
                        'url' => 'https://www.dropbox.com/s/abc123/testfile.png',
                    ],
                ],
                'httpresponses' => [
                    new ConnectException(
                        'Connection refused',
                        new Request('GET', 'https://www.dropbox.com/s/abc123/testfile.png?dl=1'),
                    ),
                    new ConnectException(
                        'Connection refused',
                        new Request('HEAD', 'https://www.dropbox.com/s/abc123/testfile.png?dl=1'),
                    ),
                ],
                'expectsync' => 'missing',
                'expectedrequestcount' => 2,
                'expectedmethods' => ['GET', 'HEAD'],
                'expectedresult' => true,
            ],
            'image download returns error status, head request returns error status' => [
                'storedfileargs' => [
                    'reference' => [
                        'path' => '/folder/testfile.png',
                        'url' => 'https://www.dropbox.com/s/abc123/testfile.png',
                    ],
                ],
                'httpresponses' => [
                    new Response(404),
                    new Response(404),
                ],
                'expectsync' => 'missing',
                'expectedrequestcount' => 2,
                'expectedmethods' => ['GET', 'HEAD'],
                'expectedresult' => true,
            ],
            'image download returns error status, head response without content-length' => [
                'storedfileargs' => [
                    'reference' => [
                        'path' => '/folder/testfile.png',
                        'url' => 'https://www.dropbox.com/s/abc123/testfile.png',
                    ],
                ],
                'httpresponses' => [
                    new Response(404),
                    new Response(200),
                ],
                'expectsync' => 'missing',
                'expectedrequestcount' => 2,
                'expectedmethods' => ['GET', 'HEAD'],
                'expectedresult' => true,
            ],
            'non-image file head request provides the size' => [
                'storedfileargs' => [
                    'reference' => [
                        'path' => '/folder/testfile.txt',
                        'url' => 'https://www.dropbox.com/s/abc123/testfile.txt',
                    ],
                ],
                'httpresponses' => [
                    new Response(200, ['Content-Length' => '1234']),
                ],
                'expectsync' => 'size',
                'expectedrequestcount' => 1,
                'expectedmethods' => ['HEAD'],
                'expectedresult' => true,
            ],
            'non-image file head request fails' => [
                'storedfileargs' => [
                    'reference' => [
                        'path' => '/folder/testfile.txt',
                        'url' => 'https://www.dropbox.com/s/abc123/testfile.txt',
                    ],
                ],
                'httpresponses' => [
                    new ConnectException(
                        'Connection refused',
                        new Request('HEAD', 'https://www.dropbox.com/s/abc123/testfile.txt?dl=1'),
                    ),
                ],
                'expectsync' => 'missing',
                'expectedrequestcount' => 1,
                'expectedmethods' => ['HEAD'],
                'expectedresult' => true,
            ],
        ];
    }

    /**
     * Test the sync_reference function.
     *
     * @dataProvider sync_reference_provider
     * @param   array       $storedfileargs          The stored file setup
     * @param   array       $httpresponses           The responses returned by the mocked http client
     * @param   string      $expectsync              The expected sync operation
     * @param   int         $expectedrequestcount    The expected number of http requests
     * @param   array       $expectedmethods         The expected request methods
     * @param   bool        $expectedresult          The expected return value
     */
    public function test_sync_reference(
        array $storedfileargs,
        array $httpresponses,
        string $expectsync,
        int $expectedrequestcount,
        array $expectedmethods,
        bool $expectedresult,
    ): void {
        $this->resetAfterTest(true);

        $repo = $this->getMockBuilder(\repository_dropbox::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fix_old_style_reference'])
            ->getMock();
        $repo->method('fix_old_style_reference')
            ->willReturnArgument(0);

        $storedfile = $this->createMock(\stored_file::class);

        if (!empty($storedfileargs['lastsyncrecent'])) {
            $storedfile->method('get_referencelastsync')->willReturn(DAYSECS + time());
        } else {
            $storedfile->method('get_referencelastsync')->willReturn(null);
        }

        $storedfile->method('get_reference')
            ->willReturn(serialize((object) $storedfileargs['reference']));

        if ($expectsync === 'content') {
            $storedfile->expects($this->once())
                ->method('set_synchronised_content_from_file')
                ->with($this->callback(function ($path) {
                    return is_file($path);
                }));
            $storedfile->expects($this->never())->method('set_synchronized');
            $storedfile->expects($this->never())->method('set_missingsource');
        } else if ($expectsync === 'size') {
            $storedfile->expects($this->once())
                ->method('set_synchronized')
                ->with(null, 1234);
            $storedfile->expects($this->never())->method('set_synchronised_content_from_file');
            $storedfile->expects($this->never())->method('set_missingsource');
        } else if ($expectsync === 'missing') {
            $storedfile->expects($this->once())
                ->method('set_missingsource');
            $storedfile->expects($this->never())->method('set_synchronised_content_from_file');
            $storedfile->expects($this->never())->method('set_synchronized');
        } else {
            $storedfile->expects($this->never())->method('set_synchronised_content_from_file');
            $storedfile->expects($this->never())->method('set_synchronized');
            $storedfile->expects($this->never())->method('set_missingsource');
        }

        $history = [];
        if (!empty($httpresponses)) {
            ['mock' => $mock] = $this->get_mocked_http_client($history);
            foreach ($httpresponses as $response) {
                $mock->append($response);
            }
        }

        $actualresult = $repo->sync_reference($storedfile);
        $this->assertEquals($expectedresult, $actualresult);

        // Ensure that the correct requests were made.
        $this->assertCount($expectedrequestcount, $history);
        if ($expectedrequestcount > 0) {
            $methods = array_map(function (array $entry): string {
                return $entry['request']->getMethod();
            }, $history);
            $this->assertEquals($expectedmethods, $methods);
            $this->assertEquals(
                $this->get_file_download_link($storedfileargs['reference']['url']),
                (string) $history[0]['request']->getUri(),
            );
        }
    }

    /**
     * Get the expected download link for the provided shared URL.
     *
     * @param   string      $sharedurl  The URL received from the Dropbox API
     * @return  string                  The download URL
     */
    private function get_file_download_link(string $sharedurl): string {
        $url = new \moodle_url($sharedurl);
        $url->param('dl', 1);

        return $url->out(false);
    }
}
