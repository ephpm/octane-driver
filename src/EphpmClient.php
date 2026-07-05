<?php

declare(strict_types=1);

namespace Ephpm\Octane;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request as IlluminateRequest;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Octane {@see Client} implementation that bridges Laravel Octane to ePHPm's
 * persistent worker primitives.
 *
 * Octane's engine-neutral {@see \Laravel\Octane\Worker} calls three methods on a
 * client: {@see marshalRequest()} to turn engine request data into an
 * {@see IlluminateRequest}, {@see respond()} to emit the finished response, and
 * {@see error()} to emit a fallback when the app cannot even produce a response.
 *
 * The ePHPm request {@see \Ephpm\Worker\Envelope} is carried inside Octane's own
 * {@see RequestContext} at `$context->data['envelope']` — Octane owns the
 * RequestContext class, so we cannot subclass it and instead stash our payload
 * in its public `data` array (see {@see \Ephpm\Octane\RequestContext} for the
 * thin typed carrier used by the worker loop and tests).
 *
 * The engine deliberately does NOT parse request bodies: `Envelope::parsedBody()`
 * is always null and `Envelope::files()` is always empty. This client therefore
 * parses `application/x-www-form-urlencoded` and `multipart/form-data` bodies
 * itself (for POST/PUT/PATCH/DELETE), spooling uploaded files to temp files that
 * are unlinked after the request via {@see cleanupRequestTempFiles()}. Likewise
 * the engine's `query()`/`cookies()` arrays are raw (not url-decoded), so the
 * query string is re-parsed with `parse_str()` and cookie names/values are
 * url-decoded here.
 *
 * Responses: buffered responses go through `send_response()`; streaming
 * responses ({@see StreamedResponse}, {@see BinaryFileResponse}) go through
 * `send_response_stream()`, which streams the resource to the client in chunks
 * with backpressure. StreamedResponse output is captured into a `php://temp`
 * stream first (PHP spools it to disk past ~2 MB, keeping memory flat). The
 * request body is still buffered as a string (`Envelope::rawBody()`).
 *
 * Multiple `Set-Cookie` response headers are sent as a list array
 * (`'Set-Cookie' => [$c1, $c2]`), which the engine emits as one wire header per
 * element — comma-joining Set-Cookie would corrupt cookies whose `expires=`
 * attribute contains a comma.
 */
final class EphpmClient implements Client
{
    /**
     * Whether a response has been sent for the request currently being handled.
     *
     * Reset by {@see marshalRequest()}, set by {@see respond()} / {@see error()}.
     * The worker loop consults {@see hasResponded()} so its last-resort 500
     * fallback never double-sends after a throwable that escapes AFTER a
     * response already went out (double-send = engine protocol desync).
     */
    private bool $responded = false;

    /**
     * Temp files created for the current request's multipart uploads, unlinked
     * by {@see cleanupRequestTempFiles()} after the request completes.
     *
     * Static because the upload parser runs in static seams; a worker process
     * handles exactly one request at a time, so there is no overlap.
     *
     * @var list<string>
     */
    private static array $requestTempFiles = [];

    /**
     * Marshal an ePHPm request Envelope (carried in `$context->data['envelope']`)
     * into an Illuminate request.
     *
     * @return array{0: IlluminateRequest, 1: RequestContext}
     */
    public function marshalRequest(RequestContext $context): array
    {
        // A new request is in flight: nothing has been sent for it yet.
        $this->responded = false;

        /** @var object $envelope an Ephpm\Worker\Envelope (or compatible fake) */
        $envelope = $context->data['envelope'] ?? null;

        if ($envelope === null) {
            throw new \RuntimeException(
                'EphpmClient::marshalRequest(): no envelope in RequestContext->data.',
            );
        }

        $request = self::toIlluminateRequest($envelope);

        return [$request, $context];
    }

    /**
     * Emit the finished Octane response through ePHPm's `send_response()` /
     * `send_response_stream()`.
     *
     * The `$outputBuffer` (anything the app echoed outside the Response, captured
     * by Octane) is prepended to the response body.
     */
    public function respond(RequestContext $context, OctaneResponse $response): void
    {
        unset($context);

        try {
            $this->sendSymfonyResponse($response->response, $response->outputBuffer);
        } finally {
            self::cleanupRequestTempFiles();
        }
    }

    /**
     * Emit a generic 500 when the app failed to produce a response at all.
     *
     * We deliberately do not leak the exception message to the client; the
     * application's own error handling/logging is expected to surface it.
     */
    public function error(Throwable $e, Application $app, IlluminateRequest $request, RequestContext $context): void
    {
        unset($e, $app, $request, $context);

        $this->responded = true;

        try {
            \Ephpm\Worker\send_response(
                500,
                ['Content-Type' => 'text/plain; charset=utf-8'],
                'Internal Server Error',
            );
        } finally {
            self::cleanupRequestTempFiles();
        }
    }

    /**
     * Whether {@see respond()} / {@see error()} already sent a response for the
     * request currently being handled (reset by {@see marshalRequest()}).
     */
    public function hasResponded(): bool
    {
        return $this->responded;
    }

    /**
     * Send a Symfony response through the appropriate ePHPm primitive.
     *
     * Buffered responses use `send_response()`. {@see BinaryFileResponse} is
     * streamed straight from its file; {@see StreamedResponse} output is
     * captured into a `php://temp` stream (spills to disk past ~2 MB) and
     * streamed from there. Public so the streaming dispatch is unit-testable
     * without the Octane package (tests shim the `\Ephpm\Worker` functions).
     */
    public function sendSymfonyResponse(SymfonyResponse $response, ?string $outputBuffer): void
    {
        // Mark as responded up front: once we attempt a send, a fallback 500
        // from the worker loop must never fire (risking a double-send).
        $this->responded = true;

        $status = $response->getStatusCode();
        $headers = self::flattenHeaders($response);

        if ($response instanceof BinaryFileResponse) {
            $stream = self::openBinaryFileStream($response, $outputBuffer);

            try {
                \Ephpm\Worker\send_response_stream($status, $headers, $stream);
            } finally {
                if (\is_resource($stream)) {
                    \fclose($stream);
                }
            }

            return;
        }

        if ($response instanceof StreamedResponse) {
            $stream = self::captureStreamedResponse($response, $outputBuffer);

            try {
                \Ephpm\Worker\send_response_stream($status, $headers, $stream);
            } finally {
                if (\is_resource($stream)) {
                    \fclose($stream);
                }
            }

            return;
        }

        [, , $content] = self::translateSymfonyResponse($response, $outputBuffer);

        \Ephpm\Worker\send_response($status, $headers, $content);
    }

    /**
     * Unlink the temp files created for the current request's uploads.
     *
     * Idempotent; files already moved/removed by the application are skipped.
     */
    public static function cleanupRequestTempFiles(): void
    {
        foreach (self::$requestTempFiles as $path) {
            if ($path !== '' && \is_file($path)) {
                @\unlink($path);
            }
        }
        self::$requestTempFiles = [];
    }

    // ---------------------------------------------------------------------
    // Testable seams — pure functions with no native-primitive or container
    // dependency, so they can be exercised with a fake envelope / stub response.
    // ---------------------------------------------------------------------

    /**
     * Build an Illuminate request from an Envelope's superglobal-shaped data.
     *
     * @param object $envelope an Ephpm\Worker\Envelope (or a compatible fake)
     */
    public static function toIlluminateRequest(object $envelope): IlluminateRequest
    {
        return IlluminateRequest::createFromBase(self::toSymfonyRequest($envelope));
    }

    /**
     * Build a Symfony request from an Envelope's superglobal-shaped data.
     *
     * The engine hands us raw material only: `query()`/`cookies()` are NOT
     * url-decoded, `parsedBody()` is always null, and `files()` is always empty.
     * So this method:
     *
     *   - re-parses the query string with `parse_str()` (url-decodes and handles
     *     `a[]=` bracket syntax),
     *   - url-decodes cookie names and values,
     *   - parses `application/x-www-form-urlencoded` bodies for
     *     POST/PUT/PATCH/DELETE into the request parameter bag, and
     *   - parses `multipart/form-data` bodies into fields plus `$_FILES`-shaped
     *     uploads spooled to temp files.
     *
     * @param object $envelope an Ephpm\Worker\Envelope (or a compatible fake)
     */
    public static function toSymfonyRequest(object $envelope): SymfonyRequest
    {
        /**
         * @var array<string, mixed> $server
         * @var string               $rawBody
         */
        $server = self::normalizeServer($envelope->serverVars(), $envelope->headers());
        $rawBody = $envelope->rawBody();

        // The engine's query() is split on & and = only — never url-decoded.
        // Re-parse the query string ourselves: parse_str url-decodes and
        // handles bracket (a[]=) syntax exactly like PHP would for $_GET.
        \parse_str(self::queryString($server), $query);

        // Cookie names and values arrive raw from the engine.
        $cookies = [];
        foreach ($envelope->cookies() as $name => $value) {
            $cookies[\urldecode((string) $name)] = \urldecode((string) $value);
        }

        [$post, $files] = self::parseBody(
            \strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            isset($server['CONTENT_TYPE']) ? (string) $server['CONTENT_TYPE'] : null,
            $rawBody,
        );

        return new SymfonyRequest(
            $query,
            $post,
            [],        // attributes
            $cookies,
            $files,
            $server,
            $rawBody,
        );
    }

    /**
     * Parse a request body into `[$post, $files]`.
     *
     * The engine never parses bodies (`parsedBody()` is always null), so both
     * form content types are handled here for every body-carrying method — PHP
     * itself would only populate `$_POST` for POST:
     *
     *   - `application/x-www-form-urlencoded` → `parse_str()` into `$post`.
     *   - `multipart/form-data` → fields into `$post`, uploads into a
     *     `$_FILES`-shaped array (each file spooled to a temp file registered
     *     for {@see cleanupRequestTempFiles()}).
     *
     * Anything else (JSON, raw) leaves both empty — the app decodes the raw body.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function parseBody(string $method, ?string $contentType, string $rawBody): array
    {
        if (
            $rawBody === ''
            || $contentType === null
            || !\in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
        ) {
            return [[], []];
        }

        $ct = \strtolower($contentType);

        if (\str_starts_with($ct, 'application/x-www-form-urlencoded')) {
            \parse_str($rawBody, $post);

            return [$post, []];
        }

        if (\str_starts_with($ct, 'multipart/form-data')) {
            // Boundary extraction must use the original header — the token is
            // case-sensitive.
            $boundary = self::multipartBoundary($contentType);
            if ($boundary === null) {
                return [[], []];
            }

            return self::parseMultipart($rawBody, $boundary);
        }

        return [[], []];
    }

    /**
     * Extract the boundary token from a multipart Content-Type header.
     */
    public static function multipartBoundary(string $contentType): ?string
    {
        if (\preg_match('/boundary=(?:"([^"]+)"|([^;,\s]+))/i', $contentType, $m) !== 1) {
            return null;
        }

        $boundary = $m[1] !== '' ? $m[1] : ($m[2] ?? '');

        return $boundary !== '' ? $boundary : null;
    }

    /**
     * Parse a `multipart/form-data` body into `[$post, $files]`.
     *
     * A pragmatic parser (ported from ephpm/wordpress-worker): it covers simple
     * and nested (`name[]`, `name[key]`) field names and file uploads. It does
     * NOT try to be a byte-perfect clone of PHP's C rfc1867 parser.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function parseMultipart(string $body, string $boundary): array
    {
        $post = [];
        $files = [];

        $delimiter = '--' . $boundary;
        // Split on the delimiter; drop the preamble and the closing "--" epilogue.
        $parts = \explode($delimiter, $body);
        foreach ($parts as $part) {
            $part = \ltrim($part, "\r\n");
            if ($part === '' || \str_starts_with($part, '--')) {
                continue;
            }

            $split = \preg_split("/\r\n\r\n/", $part, 2);
            if ($split === false || \count($split) < 2) {
                continue;
            }
            [$rawHeaders, $value] = $split;
            // Strip the trailing CRLF that precedes the next delimiter.
            $value = \preg_replace('/\r\n$/', '', $value) ?? $value;

            $headers = self::parsePartHeaders($rawHeaders);
            $disposition = $headers['content-disposition'] ?? '';

            if (\preg_match('/name="([^"]*)"/', $disposition, $nm) !== 1) {
                continue;
            }
            $name = $nm[1];

            if (\preg_match('/filename="([^"]*)"/', $disposition, $fm) === 1) {
                self::assignFile(
                    $files,
                    $name,
                    $fm[1],
                    $headers['content-type'] ?? 'application/octet-stream',
                    $value,
                );

                continue;
            }

            self::assignField($post, $name, $value);
        }

        // Drop the internal bracket-accumulation scratch key.
        unset($post['__ephpm_bracket_buf__']);

        return [$post, $files];
    }

    /**
     * Translate an {@see OctaneResponse} into the
     * `[status, headers, body]` triple passed to `send_response()`.
     *
     * Delegates to {@see translateSymfonyResponse()}, which takes the two public
     * fields Octane exposes directly so it can be unit-tested without the Octane
     * package present. Only meaningful for buffered responses — streaming types
     * are dispatched by {@see sendSymfonyResponse()}.
     *
     * @return array{0: int, 1: array<string, string|list<string>>, 2: string}
     */
    public static function translateResponse(OctaneResponse $octaneResponse): array
    {
        return self::translateSymfonyResponse($octaneResponse->response, $octaneResponse->outputBuffer);
    }

    /**
     * Pure core of {@see translateResponse()}: turn a Symfony response plus an
     * optional captured output buffer into the `send_response()` triple.
     *
     * @return array{0: int, 1: array<string, string|list<string>>, 2: string}
     */
    public static function translateSymfonyResponse(SymfonyResponse $response, ?string $outputBuffer): array
    {
        $content = (string) $response->getContent();
        if ($outputBuffer !== null && $outputBuffer !== '') {
            // Anything the app echoed outside the Response is prepended, matching
            // Octane's own drivers.
            $content = $outputBuffer . $content;
        }

        return [$response->getStatusCode(), self::flattenHeaders($response), $content];
    }

    /**
     * Flatten a Symfony response's case-preserving headers into the
     * name-keyed map `send_response()` / `send_response_stream()` expect.
     *
     * Ordinary multi-value headers are comma-joined per RFC 9110. `Set-Cookie`
     * is the exception: cookie `expires=` attributes contain commas, so the
     * engine's array-value contract is used instead — a list of strings emits
     * one wire header per element.
     *
     * `allPreserveCaseWithoutCookies()` intentionally excludes `Set-Cookie`
     * (Symfony manages cookies through a dedicated bag), so cookies are added
     * back explicitly from `getCookies()`.
     *
     * @return array<string, string|list<string>>
     */
    public static function flattenHeaders(SymfonyResponse $response): array
    {
        $flat = [];
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $flat[$name] = \implode(', ', $values);
        }

        $setCookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            /** @var Cookie $cookie */
            $setCookies[] = (string) $cookie;
        }
        if ($setCookies !== []) {
            $flat['Set-Cookie'] = $setCookies;
        }

        return $flat;
    }

    // ---------------------------------------------------------------------
    // Private helpers.
    // ---------------------------------------------------------------------

    /**
     * The raw query string for a request: `QUERY_STRING` when present,
     * otherwise the part of `REQUEST_URI` after `?`.
     *
     * @param array<string, mixed> $server
     */
    private static function queryString(array $server): string
    {
        $qs = isset($server['QUERY_STRING']) ? (string) $server['QUERY_STRING'] : '';
        if ($qs !== '') {
            return $qs;
        }

        $uri = isset($server['REQUEST_URI']) ? (string) $server['REQUEST_URI'] : '';
        $pos = \strpos($uri, '?');

        return $pos === false ? '' : \substr($uri, $pos + 1);
    }

    /**
     * Open the readable stream for a {@see BinaryFileResponse}, prepending any
     * captured output buffer via a `php://temp` spool when necessary.
     *
     * @return resource
     */
    private static function openBinaryFileStream(BinaryFileResponse $response, ?string $outputBuffer)
    {
        $path = $response->getFile()->getPathname();
        $file = \fopen($path, 'rb');
        if ($file === false) {
            throw new \RuntimeException(
                "EphpmClient: unable to open response file for streaming: {$path}",
            );
        }

        if ($outputBuffer === null || $outputBuffer === '') {
            return $file;
        }

        // Rare: the app echoed output outside the Response. Splice it in front
        // of the file through a php://temp spool (disk-backed past ~2 MB).
        $spool = self::openTempStream();
        \fwrite($spool, $outputBuffer);
        \stream_copy_to_stream($file, $spool);
        \fclose($file);
        \rewind($spool);

        return $spool;
    }

    /**
     * Run a {@see StreamedResponse}'s content callback, capturing its output
     * into a rewound `php://temp` stream (disk-backed past ~2 MB, so memory
     * stays flat for large responses).
     *
     * @return resource
     */
    private static function captureStreamedResponse(StreamedResponse $response, ?string $outputBuffer)
    {
        $stream = self::openTempStream();

        if ($outputBuffer !== null && $outputBuffer !== '') {
            \fwrite($stream, $outputBuffer);
        }

        \ob_start(
            static function (string $chunk) use ($stream): string {
                \fwrite($stream, $chunk);

                return ''; // nothing reaches the real output buffer
            },
            8192,
        );

        try {
            $response->sendContent();
        } finally {
            // Flushes the final partial chunk through the callback above.
            \ob_end_clean();
        }

        \rewind($stream);

        return $stream;
    }

    /**
     * Open a fresh `php://temp` read/write stream.
     *
     * @return resource
     */
    private static function openTempStream()
    {
        $stream = \fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('EphpmClient: unable to open php://temp stream.');
        }

        return $stream;
    }

    /**
     * Assign a scalar form field into `$post`, honouring `name[]` / `name[key]`
     * bracket syntax.
     *
     * PHP's rfc1867/urlencoded parser treats each `name[]` occurrence as an
     * append (successive values get indices 0, 1, 2, …). We reproduce that by
     * collecting all bracketed fields for the whole part list into a single
     * urlencoded query string and letting `parse_str` build the nested array in
     * one pass.
     *
     * @param array<string, mixed> $post
     */
    private static function assignField(array &$post, string $name, string $value): void
    {
        if (!\str_contains($name, '[')) {
            $post[$name] = $value;

            return;
        }

        // Accumulate bracketed pairs in a hidden query buffer, then re-parse the
        // whole buffer so parse_str applies real append/merge semantics.
        $buffer = $post['__ephpm_bracket_buf__'] ?? '';
        if ($buffer !== '') {
            $buffer .= '&';
        }
        $buffer .= self::encodeBracketName($name) . '=' . \rawurlencode($value);
        $post['__ephpm_bracket_buf__'] = $buffer;

        $parsed = [];
        \parse_str($buffer, $parsed);
        foreach ($parsed as $k => $v) {
            $post[$k] = $v;
        }
    }

    /**
     * URL-encode a bracketed field name so parse_str keeps the brackets but
     * encodes the key/segments.
     */
    private static function encodeBracketName(string $name): string
    {
        return \preg_replace_callback(
            '/[^\[\]]+/',
            static fn (array $m): string => \rawurlencode($m[0]),
            $name,
        ) ?? $name;
    }

    /**
     * Spool one uploaded file to a temp path, register the path for post-request
     * cleanup, and record it in `$files` in `$_FILES` shape (which Symfony's
     * FileBag converts into an {@see \Symfony\Component\HttpFoundation\File\UploadedFile}).
     *
     * @param array<string, mixed> $files
     */
    private static function assignFile(
        array &$files,
        string $name,
        string $filename,
        string $type,
        string $content,
    ): void {
        $tmp = \tempnam(\sys_get_temp_dir(), 'ephpm-oct');
        $error = \UPLOAD_ERR_OK;
        if ($tmp === false) {
            $tmp = '';
            $error = \UPLOAD_ERR_CANT_WRITE;
        } elseif (\file_put_contents($tmp, $content) === false) {
            $error = \UPLOAD_ERR_CANT_WRITE;
        }

        if ($tmp !== '') {
            self::$requestTempFiles[] = $tmp;
        }

        $files[$name] = [
            'name' => $filename,
            'type' => $type,
            'tmp_name' => $tmp,
            'error' => $error,
            'size' => \strlen($content),
        ];
    }

    /**
     * Parse the header block of one multipart part into a lowercased map.
     *
     * @return array<string, string>
     */
    private static function parsePartHeaders(string $rawHeaders): array
    {
        $headers = [];
        foreach (\preg_split("/\r\n/", $rawHeaders) ?: [] as $line) {
            $pos = \strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $key = \strtolower(\trim(\substr($line, 0, $pos)));
            $headers[$key] = \trim(\substr($line, $pos + 1));
        }

        return $headers;
    }

    /**
     * Ensure the server bag carries the request headers as `HTTP_*` entries so
     * Symfony's HeaderBag is populated (some engines only put a subset of
     * headers in the server array). Duplicate request headers arrive from the
     * engine pre-joined with ", ".
     *
     * @param array<string, mixed>  $server
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private static function normalizeServer(array $server, array $headers): array
    {
        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . \strtoupper(\str_replace('-', '_', (string) $name));
            // Content-Type / Content-Length live un-prefixed in the server bag.
            if ($key === 'HTTP_CONTENT_TYPE') {
                $server['CONTENT_TYPE'] ??= $value;
            } elseif ($key === 'HTTP_CONTENT_LENGTH') {
                $server['CONTENT_LENGTH'] ??= $value;
            }
            $server[$key] ??= $value;
        }

        return $server;
    }
}
