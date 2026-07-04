<?php

declare(strict_types=1);

namespace Ephpm\Octane;

use Laravel\Octane\RequestContext as OctaneRequestContext;

/**
 * A thin, strongly-typed carrier for the ePHPm request Envelope used by the
 * worker loop and tests.
 *
 * ## Why this class exists (a documented deviation)
 *
 * Laravel Octane ships its OWN `Laravel\Octane\RequestContext` — a loosely-typed
 * bag with a `public array $data`, `ArrayAccess`, and magic `__get`/`__set`.
 * Octane's engine-neutral {@see \Laravel\Octane\Worker} accepts and threads that
 * class through {@see \Laravel\Octane\Contracts\Client::marshalRequest()} and
 * `respond()`. We therefore CANNOT replace Octane's class with our own; the real
 * request path always uses `Laravel\Octane\RequestContext`.
 *
 * What we can do is agree on a convention for WHERE our payload lives inside
 * Octane's context: the Envelope is stored at `$octaneContext->data['envelope']`.
 * This class is the typed helper that produces / reads that convention, so the
 * bin script and unit tests don't hand-write `->data['envelope']` everywhere and
 * so the contract is expressed in one place.
 *
 * It is deliberately NOT a subclass of Octane's RequestContext — keeping it a
 * plain value object means it can be constructed and asserted on in tests
 * without the Octane package present.
 */
final class RequestContext
{
    /**
     * @param object $envelope an Ephpm\Worker\Envelope (or a compatible fake)
     */
    public function __construct(public readonly object $envelope)
    {
    }

    /**
     * Build our typed carrier from Octane's context by reading the agreed
     * `data['envelope']` slot.
     */
    public static function fromOctane(OctaneRequestContext $context): self
    {
        $envelope = $context->data['envelope'] ?? null;

        if (!\is_object($envelope)) {
            throw new \RuntimeException(
                'RequestContext::fromOctane(): expected an object envelope at data[\'envelope\'].',
            );
        }

        return new self($envelope);
    }

    /**
     * Produce an Octane RequestContext carrying this envelope under the agreed
     * `data['envelope']` key — the exact value the worker loop passes to
     * `Worker::handle()`.
     */
    public function toOctane(): OctaneRequestContext
    {
        return new OctaneRequestContext(['envelope' => $this->envelope]);
    }
}
