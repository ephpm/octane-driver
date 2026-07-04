<?php

declare(strict_types=1);

namespace Ephpm\Octane\Tests;

use Ephpm\Octane\EphpmClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Exercises the request- and response-translation logic WITHOUT the Laravel /
 * Octane runtime and WITHOUT the native ePHPm worker primitives.
 *
 * We drive the pure static seams on {@see EphpmClient} directly:
 *   - {@see EphpmClient::toSymfonyRequest()} with a fake Envelope, and
 *   - {@see EphpmClient::translateSymfonyResponse()} / {@see EphpmClient::flattenHeaders()}
 *     with a real Symfony Response plus an optional output buffer.
 *
 * These two response seams take exactly the two public fields Octane's
 * OctaneResponse exposes (`$response`, `$outputBuffer`), so they are testable
 * without the Octane package present. {@see EphpmClient::translateResponse()}
 * is the thin OctaneResponse-typed adapter that forwards to them at runtime.
 *
 * `toIlluminateRequest()`, `marshalRequest()`, `respond()`, and `error()` are
 * intentionally NOT unit-tested here: they either require the Illuminate
 * container (`Request::createFromBase`) or call the native `send_response()`
 * primitive. Their logic is a thin wrapper over the seams tested below; the
 * integration is covered by the examples in `examples/laravel.md`.
 */
final class EphpmClientTest extends TestCase
{
    // -------------------- Request mapping (Envelope -> Symfony) --------------

    public function testToSymfonyRequestMapsMethodQueryCookies(): void
    {
        $request = EphpmClient::toSymfonyRequest(self::fakeEnvelope());

        self::assertSame('POST', $request->getMethod());
        self::assertSame('2', $request->query->get('page'));
        self::assertSame('xyz', $request->cookies->get('sid'));
        self::assertSame('localhost', $request->headers->get('Host'));
    }

    public function testToSymfonyRequestExposesRawBody(): void
    {
        $request = EphpmClient::toSymfonyRequest(self::fakeEnvelope());

        self::assertSame('body-bytes', $request->getContent());
    }

    public function testToSymfonyRequestUsesParsedBodyForPost(): void
    {
        $envelope = self::fakeEnvelope(
            server: ['REQUEST_METHOD' => 'POST'],
            headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
            parsedBody: ['name' => 'ada'],
            rawBody: 'name=ada',
        );

        $request = EphpmClient::toSymfonyRequest($envelope);

        self::assertSame('ada', $request->request->get('name'));
    }

    /**
     * The Swoole-converter behaviour we replicate: PHP never populates the POST
     * bag for PUT/PATCH/DELETE, so a form-urlencoded body must be parsed out of
     * the raw body by us.
     */
    public function testToSymfonyRequestParsesFormBodyForPut(): void
    {
        $envelope = self::fakeEnvelope(
            server: ['REQUEST_METHOD' => 'PUT'],
            headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
            parsedBody: null,
            rawBody: 'title=Hello&draft=1',
        );

        $request = EphpmClient::toSymfonyRequest($envelope);

        self::assertSame('PUT', $request->getMethod());
        self::assertSame('Hello', $request->request->get('title'));
        self::assertSame('1', $request->request->get('draft'));
    }

    public function testToSymfonyRequestParsesFormBodyForDelete(): void
    {
        $envelope = self::fakeEnvelope(
            server: ['REQUEST_METHOD' => 'DELETE'],
            headers: ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'],
            parsedBody: null,
            rawBody: 'confirm=yes',
        );

        $request = EphpmClient::toSymfonyRequest($envelope);

        self::assertSame('yes', $request->request->get('confirm'));
    }

    public function testToSymfonyRequestDoesNotParseJsonBodyForPut(): void
    {
        $envelope = self::fakeEnvelope(
            server: ['REQUEST_METHOD' => 'PUT'],
            headers: ['Content-Type' => 'application/json'],
            parsedBody: null,
            rawBody: '{"title":"Hello"}',
        );

        $request = EphpmClient::toSymfonyRequest($envelope);

        // JSON is NOT form-decoded into the parameter bag; the app decodes it.
        self::assertNull($request->request->get('title'));
        self::assertSame('{"title":"Hello"}', $request->getContent());
    }

    // -------------------- Response mapping (Octane -> send_response) ---------

    public function testTranslateResponseReturnsStatusHeadersBody(): void
    {
        $response = new SymfonyResponse(
            '{"ok":true}',
            201,
            ['Content-Type' => 'application/json', 'X-Trace' => 'abc'],
        );

        [$status, $headers, $body] = EphpmClient::translateSymfonyResponse($response, null);

        self::assertSame(201, $status);
        self::assertSame('application/json', $headers['Content-Type']);
        self::assertSame('abc', $headers['X-Trace']);
        self::assertSame('{"ok":true}', $body);
    }

    public function testTranslateResponsePrependsOutputBuffer(): void
    {
        $response = new SymfonyResponse('MAIN', 200);

        [, , $body] = EphpmClient::translateSymfonyResponse($response, 'LEAKED');

        self::assertSame('LEAKEDMAIN', $body);
    }

    public function testTranslateResponseIgnoresNullOutputBuffer(): void
    {
        $response = new SymfonyResponse('MAIN', 200);

        [, , $body] = EphpmClient::translateSymfonyResponse($response, null);

        self::assertSame('MAIN', $body);
    }

    public function testFlattenHeadersJoinsMultipleValuesWithCommaSpace(): void
    {
        $response = new SymfonyResponse('', 200);
        $response->headers->set('X-Multi', ['a', 'b'], replace: true);

        $flat = EphpmClient::flattenHeaders($response);

        self::assertSame('a, b', $flat['X-Multi']);
    }

    public function testFlattenHeadersAppendsSetCookieLines(): void
    {
        $response = new SymfonyResponse('', 200);
        $response->headers->setCookie(Cookie::create('sid', 'abc123'));

        $flat = EphpmClient::flattenHeaders($response);

        self::assertArrayHasKey('Set-Cookie', $flat);
        self::assertStringContainsString('sid=abc123', $flat['Set-Cookie']);
    }

    public function testFlattenHeadersPreservesHeaderCase(): void
    {
        $response = new SymfonyResponse('', 200);
        $response->headers->set('X-Custom-Header', 'v');

        $flat = EphpmClient::flattenHeaders($response);

        self::assertArrayHasKey('X-Custom-Header', $flat);
    }

    // -------------------- Fixtures ------------------------------------------

    /**
     * A stand-in for Ephpm\Worker\Envelope with the same accessor surface.
     *
     * @param array<string, mixed>      $server
     * @param array<string, string>     $headers
     * @param array<string, mixed>      $cookies
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $parsedBody
     * @param array<string, mixed>      $files
     */
    private static function fakeEnvelope(
        array $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/thing?page=2'],
        array $headers = ['Host' => 'localhost', 'Content-Type' => 'text/plain'],
        array $cookies = ['sid' => 'xyz'],
        array $query = ['page' => '2'],
        ?array $parsedBody = null,
        array $files = [],
        string $rawBody = 'body-bytes',
    ): object {
        return new class ($server, $headers, $cookies, $query, $parsedBody, $files, $rawBody) {
            public function __construct(
                private array $server,
                private array $headers,
                private array $cookies,
                private array $query,
                private ?array $parsedBody,
                private array $files,
                private string $rawBody,
            ) {
            }

            public function serverVars(): array
            {
                return $this->server;
            }

            public function headers(): array
            {
                return $this->headers;
            }

            public function cookies(): array
            {
                return $this->cookies;
            }

            public function query(): array
            {
                return $this->query;
            }

            public function parsedBody(): ?array
            {
                return $this->parsedBody;
            }

            public function files(): array
            {
                return $this->files;
            }

            public function rawBody(): string
            {
                return $this->rawBody;
            }

            public function bodyStream(): string
            {
                return $this->rawBody;
            }
        };
    }
}
