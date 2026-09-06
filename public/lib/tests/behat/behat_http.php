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

require_once(__DIR__ . '/../../behat/behat_base.php');

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use GuzzleHttp\Psr7\Response;

/**
 * Steps to mock HTTP requests made through \core\http_client during a scenario.
 *
 * The mocked responses are served by \core\http_client itself and are therefore completely
 * agnostic of the caller (feeds, web services, external tools, ...). Requests are matched on
 * scheme, host and path - the query string is ignored - and repeat the last configured response
 * once the queue for a URL is exhausted. Requests for unmocked URLs receive a 404 response rather
 * than reaching the real network.
 *
 * Steps for the same URL build up a queue of responses:
 *
 *   Given HTTP requests to "https://example.com/feed.xml" will respond with status code "200"
 *   And HTTP requests to "https://example.com/feed.xml" will respond with the header "Content-Type" set to "application/rss+xml"
 *   And HTTP requests to "https://example.com/feed.xml" will respond with the body in "lib/tests/fixtures/rsstest.xml"
 *
 * A further status code for the same URL starts a new response in the queue.
 *
 * @package    core
 * @category   test
 * @copyright  2025 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_http extends behat_base {

    /**
     * The responses configured for the current scenario, keyed by URL.
     *
     * Each URL maps to a list of response specifications (arrays), consumed in order.
     *
     * @var array
     */
    protected $mocks = [];

    /**
     * Remove any mocked HTTP responses before each scenario so that they never leak between scenarios.
     *
     * @BeforeScenario
     *
     * @param BeforeScenarioScope $scope
     */
    public function clear_http_mocks(BeforeScenarioScope $scope) {
        \core\http_client::clear_mock_responses();
        $this->mocks = [];
    }

    /**
     * Mock a HTTP status code for requests to a given URL.
     *
     * @Given /^HTTP requests to "([^"]*)" will respond with status code "([^"]*)"$/
     * @When /^HTTP requests to "([^"]*)" will respond with status code "([^"]*)"$/
     * @Then /^HTTP requests to "([^"]*)" will respond with status code "([^"]*)"$/
     *
     * @param string $url The URL to mock.
     * @param string $code The HTTP status code.
     */
    public function http_requests_will_respond_with_status_code(string $url, string $code) {
        $this->mocks[$url][] = [
            'status' => (int) $code,
            'headers' => [],
            'body' => '',
        ];

        $this->sync_mocks();
    }

    /**
     * Mock a HTTP response header for requests to a given URL.
     *
     * The header is added to the most recently configured response for the URL.
     *
     * @Given /^HTTP requests to "([^"]*)" will respond with the header "([^"]*)" set to "([^"]*)"$/
     * @When /^HTTP requests to "([^"]*)" will respond with the header "([^"]*)" set to "([^"]*)"$/
     * @Then /^HTTP requests to "([^"]*)" will respond with the header "([^"]*)" set to "([^"]*)"$/
     *
     * @param string $url The URL to mock.
     * @param string $header The header name.
     * @param string $value The header value.
     */
    public function http_requests_will_respond_with_header(string $url, string $header, string $value) {
        if (empty($this->mocks[$url])) {
            // A header without a preceding status defaults to a 200 response.
            $this->mocks[$url][] = [
                'status' => 200,
                'headers' => [],
                'body' => '',
            ];
        }

        $index = count($this->mocks[$url]) - 1;
        $this->mocks[$url][$index]['headers'][$header] = $value;

        $this->sync_mocks();
    }

    /**
     * Mock the body of a HTTP response from a file for requests to a given URL.
     *
     * The body is set on the most recently configured response for the URL.
     *
     * @Given /^HTTP requests to "([^"]*)" will respond with the body in "([^"]*)"$/
     * @When /^HTTP requests to "([^"]*)" will respond with the body in "([^"]*)"$/
     * @Then /^HTTP requests to "([^"]*)" will respond with the body in "([^"]*)"$/
     *
     * @param string $url The URL to mock.
     * @param string $file The file to use as the response body (relative to the Moodle dirroot).
     */
    public function http_requests_will_respond_with_body_from_file(string $url, string $file) {
        global $CFG;

        $path = $CFG->dirroot . '/' . $file;
        if (!file_exists($path)) {
            throw new coding_exception("Cannot mock the HTTP response: the fixture file '$file' was not found.");
        }

        $this->http_requests_will_respond_with_body($url, file_get_contents($path));
    }

    /**
     * Mock the body of a HTTP response for requests to a given URL.
     *
     * The body is set on the most recently configured response for the URL.
     *
     * @Given /^HTTP requests to "([^"]*)" will respond with the body "([^"]*)"$/
     * @When /^HTTP requests to "([^"]*)" will respond with the body "([^"]*)"$/
     * @Then /^HTTP requests to "([^"]*)" will respond with the body "([^"]*)"$/
     *
     * @param string $url The URL to mock.
     * @param string $body The response body.
     */
    public function http_requests_will_respond_with_body(string $url, string $body) {
        if (empty($this->mocks[$url])) {
            // A body without a preceding status defaults to a 200 response.
            $this->mocks[$url][] = [
                'status' => 200,
                'headers' => [],
                'body' => '',
            ];
        }

        $index = count($this->mocks[$url]) - 1;
        $this->mocks[$url][$index]['body'] = $body;

        $this->sync_mocks();
    }

    /**
     * Install the configured mocks onto \core\http_client so that they apply to any request made
     * from here on in the scenario.
     */
    protected function sync_mocks(): void {
        $responses = [];
        foreach ($this->mocks as $url => $specs) {
            $responses[$url] = array_map(
                fn (array $spec): Response => new Response($spec['status'], $spec['headers'], $spec['body']),
                $specs
            );
        }

        \core\http_client::set_mock_responses($responses);
    }

}
