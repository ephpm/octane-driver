<?php

declare(strict_types=1);

namespace Ephpm\Octane\Tests;

use Ephpm\Octane\EphpmClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exercises the request- and response-translation logic WITHOUT the Laravel /
 * Octane runtime and WITHOUT the native ePHPm worker primitives.
 *
 * We drive the pure static seams on {@see EphpmClient} directly:
 *   - {@see EphpmClient::toSymfonyRequest()} with a fake Envelope, and
 *   - {@see EphpmClient::translateSymfonyResponse()} / {@see EphpmClient::flattenHeaders()}
 *     with a real Symfony Response,
 * plus the instance-level {@see EphpmClient::sendSymfonyResponse()} against the
 * `Ephpm\Worker` shims installed by tests/bootstrap.php (recorded by
 * {@see WorkerSpy}) — that covers buffered vs streamed dispatch and the
 * hasResponded() exactly-once tracking without a live engine.
 *
 * The fake Envelope matches the REAL engine contract: `parsedBody()` is always
 * null and `files()` is always empty (the engine never parses bodies), and
 * `query()`/`cookies()` values arrive raw (not url-decoded) — so these tests
 * exercise the driver's own parsing, exactly as production does.
 *
 * `toIlluminateRequest()`, `marshalRequest()`, `respond()`, and `error()` are
 * intentionally NOT unit-tested here: they either require the Illuminate
 * container (`Request::createFromBase`) or the Octane value classes. Their
 * logic is a thin wrapper over the seams tested below; the integration is
 * covered by the examples in `examples/laravel.md`.
 */
final class EphpmClientTest extends TestCase
{
    protected function setUp(): void
    {
        WorkerSpy::reset();
        EphpmClient::cleanupRequestTempFiles();
    }

    protected function tearDown(): void
    {
        EphpmClient::cleanupRequestTempFiles();
    }

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

    /**
     * The engine NEVER parses bodies (`parsedBody()` is always null), so a POST
     * form body must be parsed out of the raw body by the driver itself.
     */
    public function testToSymfonyRequestParsesFormBodyForPost(): void
    {
        $envelope = self::fakeEnvelope(
            server: ['REQUEST_METHOD' => 'POST'],
            headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
            rawBody: 'name=ada&nums%5B%5D=1&nums%5B%5D=2',
        );

        $request = EphpmClient::toSymfonyRequest($envelope);

        self::assertSame('ada', $request->request->get('name'));
        self::assertSame(['1', '2'], $request->request->all('nums'));
    }

    public function testToSymfonyRequestParsesFormBodyForPut(): void
    {
        $envelope = self::fakeEnvelope(
            server: ['REQUEST_METHOD' => 'PUT'],
            headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
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
            rawBody: '{"title":"Hello"}',
        );

        $request = EphpmClient::toSymfonyRequest($envelope);

        // JSON is NOT form-decoded into the parameter bag; the app decodes it.
        self::assertNull($request->request->get('title'));
        self::assertSame('{"title":"Hello"}', $request->getContent());
    }

    /**
     * The engine's query() is split on & and = only — the driver must re-parse
     * QUERY_STRING with parse_str so values are url-decoded and `a[]=` bracket
     * syntax builds arrays.
     */
    public function testToSymfonyRequestUrlDecodesQueryString(): void
    {
        $envelope = self::fakeEnvelope(
            server: [
                'REQUEST_METHOD' => 'GET',
                'QUERY_STRING' => 'q=a%20b&tags%5B%5D=x&tags%5B%5D=y',
            ],
            query: ['q' => 'a%20b'], // raw, as the engine would hand it over
            rawBody: '',
        );

        $request = EphpmClient::toSymfonyRequest($envelope);

        self::assertSame('a b', $request->query->get('q'));
        self::assertSame(['x', 'y'], $request->query->all('tags'));
    }

    /**
     * The engine's cookies() names/values arrive raw — the driver url-decodes
     * both.
     */
    public function testToSymfonyRequestUrlDecodesCookies(): void
    {
        $envelope = self::fakeEnvelope(
            cookies: ['se%20ssion' => 'v%201', 'plain' => 'ok'],
        );

        $request = EphpmClient::toSymfonyRequest($envelope);

        self::assertSame('v 1', $request->cookies->get('se ssion'));
        self::assertSame('ok', $request->cookies->get('plain'));
    }

    /**
     * The engine's files() is always empty — multipart bodies must be parsed by
     * the driver: fields into the parameter bag, uploads spooled to temp files
     * exposed as UploadedFile instances.
     */
    public function testToSymfonyRequestParsesMultipartFieldsAndFiles(): void
    {
        $boundary = 'XxBOUNDxX';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"title\"\r\n"
            . "\r\n"
            . "Hello\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"doc\"; filename=\"a.txt\"\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "file-bytes\r\n"
            . "--{$boundary}--\r\n";

        $envelope = self::fakeEnvelope(
            server: ['REQUEST_METHOD' => 'POST'],
            headers: ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
            rawBody: $body,
        );

        $request = EphpmClient::toSymfonyRequest($envelope);

        self::assertSame('Hello', $request->request->get('title'));

        $file = $request->files->get('doc');
        self::assertInstanceOf(UploadedFile::class, $file);
        self::assertSame('a.txt', $file->getClientOriginalName());
        self::assertSame('text/plain', $file->getClientMimeType());
        self::assertSame('file-bytes', \file_get_contents($file->getPathname()));

        // Spooled temp files are registered and removed by the request cleanup.
        $tmpPath = $file->getPathname();
        EphpmClient::cleanupRequestTempFiles();
        self::assertFileDoesNotExist($tmpPath);
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

    /**
     * Set-Cookie must NOT be comma-joined (cookie `expires=` contains commas):
     * the engine's array-value contract sends one wire header per list element.
     */
    public function testFlattenHeadersEmitsSetCookieAsList(): void
    {
        $response = new SymfonyResponse('', 200);
        $response->headers->setCookie(Cookie::create('sid', 'abc123'));
        $response->headers->setCookie(
            Cookie::create('remember', 'yes')->withExpires(new \DateTimeImmutable('2027-01-01 00:00:00 UTC')),
        );

        $flat = EphpmClient::flattenHeaders($response);

        self::assertArrayHasKey('Set-Cookie', $flat);
        self::assertIsArray($flat['Set-Cookie']);
        self::assertCount(2, $flat['Set-Cookie']);
        self::assertStringContainsString('sid=abc123', $flat['Set-Cookie'][0]);
        self::assertStringContainsString('remember=yes', $flat['Set-Cookie'][1]);
        // The expires attribute keeps its (comma-containing) form intact.
        self::assertStringContainsString('expires=', $flat['Set-Cookie'][1]);
    }

    public function testFlattenHeadersPreservesHeaderCase(): void
    {
        $response = new SymfonyResponse('', 200);
        $response->headers->set('X-Custom-Header', 'v');

        $flat = EphpmClient::flattenHeaders($response);

        self::assertArrayHasKey('X-Custom-Header', $flat);
    }

    // -------------------- Sending (buffered vs streamed dispatch) ------------

    public function testSendBufferedResponseUsesSendResponse(): void
    {
        $client = new EphpmClient();
        $client->sendSymfonyResponse(new SymfonyResponse('hello', 200), null);

        self::assertSame(1, WorkerSpy::$sends);
        self::assertSame('buffered', WorkerSpy::$mode);
        self::assertSame(200, WorkerSpy::$status);
        self::assertSame('hello', WorkerSpy::$body);
        self::assertTrue($client->hasResponded());
    }

    public function testSendResponseCarriesSetCookieListToTheWire(): void
    {
        $response = new SymfonyResponse('ok', 200);
        $response->headers->setCookie(Cookie::create('a', '1'));
        $response->headers->setCookie(Cookie::create('b', '2'));

        (new EphpmClient())->sendSymfonyResponse($response, null);

        self::assertSame(1, WorkerSpy::$sends);
        $sent = WorkerSpy::$headers['Set-Cookie'] ?? null;
        self::assertIsArray($sent);
        self::assertCount(2, $sent);
        self::assertStringContainsString('a=1', $sent[0]);
        self::assertStringContainsString('b=2', $sent[1]);
    }

    public function testBinaryFileResponseIsStreamedViaSendResponseStream(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'ephpm-oct-test');
        self::assertIsString($path);
        \file_put_contents($path, 'binary-file-contents');

        try {
            $client = new EphpmClient();
            $client->sendSymfonyResponse(new BinaryFileResponse($path), null);

            self::assertSame(1, WorkerSpy::$sends);
            self::assertSame('stream', WorkerSpy::$mode);
            self::assertSame(200, WorkerSpy::$status);
            self::assertSame('binary-file-contents', WorkerSpy::$streamBody);
            self::assertTrue($client->hasResponded());
        } finally {
            @\unlink($path);
        }
    }

    public function testStreamedResponseIsCapturedAndStreamed(): void
    {
        $response = new StreamedResponse(static function (): void {
            echo 'chunk-one|';
            echo 'chunk-two';
        }, 200, ['Content-Type' => 'text/plain']);

        (new EphpmClient())->sendSymfonyResponse($response, 'LEAKED|');

        self::assertSame(1, WorkerSpy::$sends);
        self::assertSame('stream', WorkerSpy::$mode);
        self::assertSame('LEAKED|chunk-one|chunk-two', WorkerSpy::$streamBody);
    }

    // -------------------- Fixtures ------------------------------------------

    /**
     * A stand-in for Ephpm\Worker\Envelope matching the REAL engine contract:
     * `parsedBody()` is ALWAYS null and `files()` is ALWAYS empty (the engine
     * never parses bodies), and query/cookie values are raw (not url-decoded).
     *
     * @param array<string, mixed>  $server
     * @param array<string, string> $headers
     * @param array<string, mixed>  $cookies
     * @param array<string, mixed>  $query
     */
    private static function fakeEnvelope(
        array $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/thing?page=2'],
        array $headers = ['Host' => 'localhost', 'Content-Type' => 'text/plain'],
        array $cookies = ['sid' => 'xyz'],
        array $query = ['page' => '2'],
        string $rawBody = 'body-bytes',
    ): object {
        return new class ($server, $headers, $cookies, $query, $rawBody) {
            public function __construct(
                private array $server,
                private array $headers,
                private array $cookies,
                private array $query,
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

            /** The real engine NEVER parses bodies. */
            public function parsedBody(): ?array
            {
                return null;
            }

            /** The real engine NEVER populates files. */
            public function files(): array
            {
                return [];
            }

            public function rawBody(): string
            {
                return $this->rawBody;
            }
        };
    }
}
