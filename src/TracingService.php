<?php
/**
 * Copyright 2020 Tais P. Hansen, Jordan Gosney
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace LaravelOpenTracing;

use Illuminate\Http\Request;
use OpenTracing\Scope;
use OpenTracing\SpanContext;
use OpenTracing\StartSpanOptions;
use OpenTracing\Tracer;
use const OpenTracing\Formats\HTTP_HEADERS;

class TracingService
{
    /**
     * @var Tracer
     */
    private $tracer;

    /**
     * @var Scope[]
     */
    private $scopes = [];

    public function __construct(Tracer $tracer)
    {
        $this->tracer = $tracer;
    }

    /**
     * Wraps a call in a trace span.
     *
     * @param \Closure $callable
     * @param string $operationName
     * @param array|StartSpanOptions $options
     * @return mixed
     * @throws \Exception
     */
    public function trace(string $operationName, \Closure $callable, $options = null)
    {
        $scope = $this->beginTrace($operationName, $options);

        try {
            return $callable();
        } finally {
            $this->endTrace($scope);
        }
    }

    /**
     * Starts a new trace span.
     *
     * @param string $operationName
     * @param array|StartSpanOptions $options
     * @return Scope
     */
    public function beginTrace(string $operationName, $options = null): Scope
    {
        $scope = $this->tracer->startActiveSpan($operationName, $options ?: []);
        $this->scopes[] = $scope;

        return $scope;
    }

    /**
     * Ends the specified or last started trace span.
     *
     * @param Scope|null $scope
     */
    public function endTrace(?Scope $scope = null): void
    {
        if ($scope === null) {
            $scope = end($this->scopes);
        }

        $scope->close();

        $keys = array_keys($this->scopes, $scope, true);

        foreach ($keys as $key) {
            unset($this->scopes[$key]);
        }
    }

    /**
     * Injects active span context into carrier.
     *
     * @return array
     */
    public function getInjectHeaders(): array
    {
        $carrier = [];

        if (($span = $this->tracer->getActiveSpan()) !== null) {
            $this->tracer->inject(
                $span->getContext(),
                HTTP_HEADERS,
                $carrier
            );
        }

        return $carrier;
    }

    /**
     * Extract span context from request.
     *
     * @param Request $request
     * @return SpanContext|null
     */
    public function extractFromHttpRequest(Request $request): ?SpanContext
    {
        return $this->tracer->extract(
            HTTP_HEADERS,
            array_map(
                static function ($v) {
                    if (is_array($v) && count($v) === 1) {
                        return $v[0];
                    }

                    return $v;
                },
                $request->header()
            )
        );
    }
}
