<?php

declare(strict_types=1);

namespace Ephpm\Octane;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request as IlluminateRequest;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
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
 * The request-building logic mirrors Octane's Swoole
 * `ConvertSwooleRequestToIlluminateRequest`: we assemble a Symfony request from
 * superglobal-shaped arrays, re-parse a form-urlencoded body for
 * PUT/PATCH/DELETE (which Symfony/PHP does not populate from `$_POST`), then
 * lift it to an Illuminate request via {@see IlluminateRequest::createFromBase()}.
 *
 * Streaming caveat: the request body is buffered as a string
 * (`Envelope::rawBody()`), and the response body is fully materialised before it
 * is handed to `send_response()`. True streaming is a later ePHPm engine phase.
 */
final class EphpmClient implements Client
{
    /**
     * Marshal an ePHPm request Envelope (carried in `$context->data['envelope']`)
     * into an Illuminate request.
     *
     * @return array{0: IlluminateRequest, 1: RequestContext}
     */
    public function marshalRequest(RequestContext $context): array
    {
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
     * Emit the finished Octane response through ePHPm's `send_response()`.
     *
     * The `$outputBuffer` (anything the app echoed outside the Response, captured
     * by Octane) is prepended to the response body. Case-preserving headers are
     * flattened to `['Name' => 'v1, v2']`, and each queued cookie is appended as
     * its own `Set-Cookie` line.
     */
    public function respond(RequestContext $context, OctaneResponse $response): void
    {
        [$status, $headers, $content] = self::translateResponse($response);

        \Ephpm\Worker\send_response($status, $headers, $content);
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

        \Ephpm\Worker\send_response(
            500,
            ['Content-Type' => 'text/plain; charset=utf-8'],
            'Internal Server Error',
        );
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
     * Mirrors Octane's `ConvertSwooleRequestToIlluminateRequest`: form-urlencoded
     * bodies on PUT/PATCH/DELETE are parsed out of the raw body and merged into
     * the request parameters, because PHP only auto-populates `$_POST` for POST.
     *
     * @param object $envelope an Ephpm\Worker\Envelope (or a compatible fake)
     */
    public static function toSymfonyRequest(object $envelope): SymfonyRequest
    {
        /**
         * @var array<string, mixed>      $server
         * @var array<string, mixed>      $cookies
         * @var array<string, mixed>      $query
         * @var array<string, mixed>|null $parsedBody
         * @var array<string, mixed>      $files
         * @var string                    $rawBody
         */
        $server = self::normalizeServer($envelope->serverVars(), $envelope->headers());
        $cookies = $envelope->cookies();
        $query = $envelope->query();
        $parsedBody = $envelope->parsedBody() ?? [];
        $files = $envelope->files();
        $rawBody = $envelope->rawBody();

        $request = new SymfonyRequest(
            $query,
            $parsedBody,
            [],        // attributes
            $cookies,
            $files,
            $server,
            $rawBody,
        );

        $method = \strtoupper((string) ($server['REQUEST_METHOD'] ?? $request->getMethod()));
        $contentType = (string) $request->headers->get('CONTENT_TYPE', '');

        // PHP only auto-parses form-urlencoded bodies for POST. For
        // PUT/PATCH/DELETE we must parse the raw body ourselves and inject it as
        // the request parameter bag — matching Octane's Swoole converter.
        if (
            \in_array($method, ['PUT', 'PATCH', 'DELETE'], true)
            && \str_starts_with($contentType, 'application/x-www-form-urlencoded')
            && $rawBody !== ''
        ) {
            \parse_str($rawBody, $parsed);
            $request->request = new \Symfony\Component\HttpFoundation\InputBag($parsed);
        }

        return $request;
    }

    /**
     * Translate an {@see OctaneResponse} into the
     * `[status, headers, body]` triple passed to `send_response()`.
     *
     * Delegates to {@see translateSymfonyResponse()}, which takes the two public
     * fields Octane exposes directly so it can be unit-tested without the Octane
     * package present.
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    public static function translateResponse(OctaneResponse $octaneResponse): array
    {
        return self::translateSymfonyResponse($octaneResponse->response, $octaneResponse->outputBuffer);
    }

    /**
     * Pure core of {@see translateResponse()}: turn a Symfony response plus an
     * optional captured output buffer into the `send_response()` triple.
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
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
     * `['Name' => 'v1, v2']` shape `send_response()` expects, then append one
     * `Set-Cookie` line per queued cookie.
     *
     * `allPreserveCaseWithoutCookies()` intentionally excludes `Set-Cookie`
     * (Symfony manages cookies through a dedicated bag), so cookies are added
     * back explicitly from `getCookies()`.
     *
     * Note: because the target header map is keyed by name, multiple cookies
     * collapse under a single `Set-Cookie` key joined by the standard
     * `send_response()` flattening. The ePHPm engine splits a comma-joined
     * `Set-Cookie` value back into individual headers on the wire. Each cookie's
     * own value never contains a bare `, ` that would be mis-split because
     * Symfony percent/URL-encodes cookie values.
     *
     * @return array<string, string>
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
            $flat['Set-Cookie'] = \implode(', ', $setCookies);
        }

        return $flat;
    }

    /**
     * Ensure the server bag carries the request headers as `HTTP_*` entries so
     * Symfony's HeaderBag is populated (some engines only put a subset of
     * headers in the server array).
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
