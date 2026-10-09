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
 * Rest API base class mapping rest api methods to endpoints with http methods, args and post body.
 *
 * @package    core
 * @copyright  2017 Damyon Wiese
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace core\oauth2;

use oauth2_client;
use coding_exception;
use GuzzleHttp\Exception\GuzzleException;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/oauthlib.php');

/**
 * Rest API base class mapping rest api methods to endpoints with http methods, args and post body.
 *
 * @copyright  2017 Damyon Wiese
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class rest {

    /** @var oauth2_client $oauthclient */
    protected $oauthclient;

    /**
     * Constructor.
     *
     * @param oauth2_client $oauthclient
     */
    public function __construct(oauth2_client $oauthclient) {
        $this->oauthclient = $oauthclient;
    }

    /**
     * Abstract function to define the functions of the rest API.
     *
     * @return array Example:
     *  [ 'listFiles' => [ 'method' => 'get', 'args' => [ 'folder' => PARAM_STRING ], 'response'  => 'json' ] ]
     */
    abstract public function get_api_functions();

    /**
     * Call a function from the Api with a set of arguments and optional data.
     *
     * @param string $functionname
     * @param array $functionargs
     * @param string $rawpost Optional param to include in the body of a post.
     * @param string $contenttype The MIME type for the request's Content-Type header.
     * @param array $headers Extra headers to send with the request.
     * @return string|stdClass
     */
    public function call($functionname, $functionargs, $rawpost = false, $contenttype = false, array $headers = []) {
        $functions = $this->get_api_functions();
        $supportedmethods = [ 'get', 'put', 'post', 'patch', 'head', 'delete' ];
        if (empty($functions[$functionname])) {
            throw new coding_exception('unsupported api functionname: ' . $functionname);
        }

        $method = $functions[$functionname]['method'];
        $endpoint = $functions[$functionname]['endpoint'];

        $responsetype = $functions[$functionname]['response'];
        if (!in_array($method, $supportedmethods)) {
            throw new coding_exception('unsupported api method: ' . $method);
        }

        $args = $functions[$functionname]['args'];
        $callargs = [];
        foreach ($args as $argname => $argtype) {
            if (isset($functionargs[$argname])) {
                $callargs[$argname] = clean_param($functionargs[$argname], $argtype);
            }
        }

        // Allow params in the URL path like /me/{parent}/children.
        foreach ($callargs as $argname => $value) {
            $newendpoint = str_replace('{' . $argname . '}', $value, $endpoint);
            if ($newendpoint != $endpoint) {
                $endpoint = $newendpoint;
                unset($callargs[$argname]);
            }
        }

        // Build the request options.
        $httpclient = $this->oauthclient->get_httpclient();
        $options = [];

        // Any extra headers supplied by the caller.
        if (!empty($headers)) {
            $options['headers'] = $headers;
        }

        // Authenticate the request with the access token of the OAuth2 client.
        $accesstoken = $this->oauthclient->get_accesstoken();
        if ($accesstoken) {
            $options['headers']['Authorization'] = 'Bearer ' . $accesstoken->token;
        }

        if ($rawpost !== false) {
            // A raw body is sent. Any remaining arguments are added to the URL as a query string.
            $queryparams = $this->oauthclient->build_post_data($callargs);
            if ($queryparams !== '') {
                $endpoint .= '?' . $queryparams;
            }
            $options['body'] = $rawpost;
            $options['headers']['Content-Type'] = !empty($contenttype) ? $contenttype : 'application/json';
        } else if ($method === 'get') {
            // Parameters are sent as a query string.
            if (!empty($callargs)) {
                $options['query'] = $callargs;
            }
            $options['headers']['Content-Type'] = 'application/json';
        } else if (in_array($method, ['put', 'post', 'patch'])) {
            // An array of parameters is sent as multipart/form-data, which matches the previous curl behaviour.
            foreach ($callargs as $argname => $argvalue) {
                $options['multipart'][] = ['name' => $argname, 'contents' => (string) $argvalue];
            }
            if (!empty($contenttype) && $contenttype !== 'multipart/form-data') {
                $options['headers']['Content-Type'] = $contenttype;
            }
        } else {
            // HEAD and DELETE requests have no body.
            $options['headers']['Content-Type'] = 'application/json';
        }

        // Return any 3xx response unaliased so that callers can read the redirect headers, e.g. the Location header.
        if ($responsetype === 'headers') {
            $options['allow_redirects'] = false;
        }

        try {
            // Do not throw an exception for HTTP error statuses, as callers handle the response body themselves.
            $response = $httpclient->request(strtoupper($method), $endpoint, array_merge($options, ['http_errors' => false]));
        } catch (GuzzleException $e) {
            throw new rest_exception($e->getMessage(), $e->getCode());
        }

        $responsebody = $response->getBody()->getContents();

        if ($responsetype == 'json') {
            $json = json_decode($responsebody);

            if (!empty($json->error)) {
                throw new rest_exception($json->error->code . ': ' . $json->error->message);
            }
            return $json;
        } else if ($responsetype == 'headers') {
            // Return the raw response headers, so callers can inspect them directly.
            $headers = [];
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    $headers[] = $name . ': ' . $value;
                }
            }
            return $headers;
        }

        return $responsebody;
    }
}
