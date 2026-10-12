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

namespace repository_s3;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\S3\Exception\PermanentRedirectException;
use GuzzleHttp\Promise\Coroutine;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Retry requests for buckets in a region other than the configured one.
 *
 * When a bucket is accessed through the endpoint of a different region, S3
 * responds with a 301 and the x-amz-bucket-region header. The SDK throws a
 * PermanentRedirectException for these responses, which this middleware
 * catches. It then builds a client for the bucket region and re-executes the
 * command on it, so the request is sent to the correct regional endpoint
 * with a valid signature.
 *
 * This restores the behaviour expected by MDL-48888 and mirrors the
 * S3MultiRegionClient region discovery.
 *
 * @package    repository_s3
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class region_redirect_middleware {

    /**
     * The following handler.
     *
     * @var callable
     */
    private $nexthandler;

    /**
     * Creates a client for a given region.
     *
     * @var callable
     */
    private $clientfactory;

    /**
     * The region the client is configured for.
     *
     * @var string
     */
    private $region;

    /**
     * Create a middleware wrapper function.
     *
     * @param callable $clientfactory Creates a client for a given region.
     * @param string $region The region the client is configured for.
     * @return callable The middleware.
     */
    public static function wrap(callable $clientfactory, string $region): callable {
        return static function (callable $handler) use ($clientfactory, $region) {
            return new self($handler, $clientfactory, $region);
        };
    }

    /**
     * Region redirect middleware constructor.
     *
     * @param callable $handler The handler to wrap.
     * @param callable $clientfactory Creates a client for a given region.
     * @param string $region The region the client is configured for.
     */
    public function __construct(callable $handler, callable $clientfactory, string $region) {
        $this->nexthandler = $handler;
        $this->clientfactory = $clientfactory;
        $this->region = $region;
    }

    /**
     * Retry the command in the bucket region on a permanent redirect.
     *
     * @param CommandInterface $command The command to execute.
     * @param RequestInterface|null $request The request to send.
     * @return PromiseInterface
     */
    public function __invoke(CommandInterface $command, ?RequestInterface $request = null): PromiseInterface {
        $next = $this->nexthandler;

        return Coroutine::of(function () use ($next, $command, $request) {
            try {
                return yield $next($command, $request);
            } catch (PermanentRedirectException $e) {
                if (empty($command['Bucket'])) {
                    throw $e;
                }
                $result = $e->getResult();
                $region = $result['@metadata']['headers']['x-amz-bucket-region'] ?? null;
                if (empty($region) || $region === $this->region) {
                    throw $e;
                }
                return yield $this->retry($command, $region);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() === 'AuthorizationHeaderMalformed') {
                    $region = $this->get_region_from_exception_body($e->getResponse());
                    if (!empty($region) && $region !== $this->region) {
                        return yield $this->retry($command, $region);
                    }
                }
                throw $e;
            }
        });
    }

    /**
     * Execute the command on a client for the given region.
     *
     * @param CommandInterface $command The command to execute.
     * @param string $region The region to use.
     * @return PromiseInterface
     */
    private function retry(CommandInterface $command, string $region): PromiseInterface {
        $client = ($this->clientfactory)($region);
        $newcommand = $client->getCommand($command->getName(), $command->toArray());
        return $client->executeAsync($newcommand);
    }

    /**
     * Get the region from the body of an AuthorizationHeaderMalformed response.
     *
     * @param \Psr\Http\Message\ResponseInterface|null $response The response, if available.
     * @return string|null The region name, or null when it cannot be determined.
     */
    private function get_region_from_exception_body(?\Psr\Http\Message\ResponseInterface $response): ?string {
        if ($response === null) {
            return null;
        }
        $body = (string) $response->getBody();
        try {
            $xml = new \SimpleXMLElement($body);
            if (!empty($xml->Region)) {
                return (string) $xml->Region;
            }
        } catch (\Throwable $e) {
            // Fall through on malformed XML.
            return null;
        }
        return null;
    }
}
