<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * The request/trace fields that tie an audit entry to everything else that happened
 * in the same unit of work (design § Observability: `trace_id` flows
 * panel → job → bridge → webhook).
 *
 * Resolved automatically at write time, so callers never pass it. Task 38.1 adds
 * `Tracer` and the `traces` table; until then this reads the trace id from whatever
 * has already been established — Laravel's `Context` (which survives queue
 * boundaries) or the inbound correlation header — and records `null` rather than
 * inventing one, because a fabricated trace id is worse than a missing one.
 */
final readonly class AuditCorrelation
{
    /**
     * Headers an upstream (nginx, the bridge, a gateway) may use to carry the id.
     *
     * @var list<string>
     */
    private const array TRACE_HEADERS = ['X-Trace-Id', 'X-Request-Id', 'X-Correlation-Id'];

    public function __construct(
        public ?string $requestId = null,
        public ?string $traceId = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {}

    /**
     * Nothing to correlate: console commands, tests, and internal calls that are not
     * part of a request.
     */
    public static function none(): self
    {
        return new self;
    }

    /**
     * Read the correlation of the current request, if there is one.
     */
    public static function capture(?Request $request = null): self
    {
        $traceId = self::fromContext('trace_id');
        $requestId = self::fromContext('request_id');

        if ($request === null && ! app()->bound('request')) {
            return new self($requestId, $traceId);
        }

        $request ??= app('request');

        if (! $request instanceof Request) {
            return new self($requestId, $traceId);
        }

        foreach (self::TRACE_HEADERS as $header) {
            if ($traceId !== null) {
                break;
            }

            $value = $request->header($header);
            $traceId = is_string($value) && $value !== '' ? $value : null;
        }

        return new self(
            $requestId,
            $traceId,
            self::truncate($request->ip(), 45),
            self::truncate($request->userAgent(), 255),
        );
    }

    private static function fromContext(string $key): ?string
    {
        $value = Context::get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function truncate(?string $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
