<?php
/*
 * Copyright (c) 2019, The Jaeger Authors
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except
 * in compliance with the License. You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software distributed under the License
 * is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express
 * or implied. See the License for the specific language governing permissions and limitations under
 * the License.
 */

namespace Jaeger;

use DateTimeInterface;
use Exception;
use Jaeger\Propagator\Propagator;
use Jaeger\Sampler\Sampler;
use Jaeger\Thrift\Process;
use OpenTracing\Scope;
use OpenTracing\ScopeManager;
use OpenTracing\Formats;
use OpenTracing\Span as OTSpan;
use OpenTracing\SpanContext as OTSpanContext;
use OpenTracing\Tracer;
use Jaeger\Reporter\Reporter;
use OpenTracing\StartSpanOptions;
use OpenTracing\Reference;
use OpenTracing\UnsupportedFormatException;
use UnexpectedValueException;
use function count;

class Jaeger implements Tracer
{

    private Reporter $reporter;

    private Sampler $sampler;

    private ScopeManager $scopeManager;

    public Propagator $propagator;

    private bool $gen128bit = false;

    /**
     * @var Span[]
     */
    public array $spans = [];

    /**
     * @var array<string, scalar|array>
     */
    public array $tags = [];

    public ?Process $process = null;

    public string $serverName;

    /**
     * @var array{
     *     serverName: string,
     *     tags: array<array-key, array{key: string, vType: string, vStr?: string, vDouble?: int, vBool?: boolean}>[],
     * }
     */
    public array $processThrift;

    public function __construct(
        string $serverName,
        Reporter $reporter,
        Sampler $sampler,
        ScopeManager $scopeManager,
        Propagator $propagator,
    ) {
        $this->reporter     = $reporter;
        $this->sampler      = $sampler;
        $this->scopeManager = $scopeManager;
        $this->propagator   = $propagator;

        $this->addTags($this->sampler->getTags());
        $this->addTags($this->getEnvTags());

        if ($serverName === '') {
            $this->serverName = (string)($_SERVER['SERVER_NAME'] ?? 'unknow server');
        } else {
            $this->serverName = $serverName;
        }

        $this->processThrift = [
            'serverName' => $this->serverName,
            'tags'       => [],
        ];
    }

    /**
     * @param array<string, scalar|array> $tags
     */
    public function setTags(array $tags = []): void
    {
        $this->tags = $tags;
    }

    /**
     * @param array<string, scalar|array> $tags
     */
    public function addTags(array $tags): void
    {
        foreach ($tags as $key => $tag) {
            $this->tags[$key] = $tag;
        }
    }

    /**
     * @param string                 $operationName
     * @param array|StartSpanOptions $options
     *
     * @return Span
     * @throws Exception
     */
    public function startSpan(string $operationName, $options = []): Span
    {

        if (!($options instanceof StartSpanOptions)) {
            $options = StartSpanOptions::create($options);
        }

        $parentSpan = $this->getParentSpanContext($options);
        if ($parentSpan === null || $parentSpan->traceIdLow === 0) {
            $low                     = $this->generateId();
            $spanId                  = (string)$low;
            $flags                   = $this->sampler->IsSampled();
            $spanContext             = new SpanContext($spanId, '0', $flags ? 1 : 0, null, 0);
            $spanContext->traceIdLow = $low;
            if ($this->gen128bit === true) {
                $spanContext->traceIdHigh = $this->generateId();
            }
        } else {
            $spanContext             = new SpanContext(
                (string)$this->generateId(),
                $parentSpan->spanId, $parentSpan->flags, $parentSpan->baggage, 0
            );
            $spanContext->traceIdLow = $parentSpan->traceIdLow;
            if ($parentSpan->traceIdHigh) {
                $spanContext->traceIdHigh = $parentSpan->traceIdHigh;
            }
        }

        $tmpStartTime = $options->getStartTime();
        if ($tmpStartTime instanceof DateTimeInterface) {
            $startTime = (int)$tmpStartTime->format('Uu');
        } else {
            $startTime = $tmpStartTime ? (int)($tmpStartTime * 1000000) : null;
        }

        $span = new Span($operationName, $spanContext, $options->getReferences(), $startTime);
        if (!empty($options->getTags())) {
            foreach ($options->getTags() as $k => $tag) {
                if (!is_scalar($tag) || !is_string($k)) {
                    throw new UnexpectedValueException(
                        'Tag contains invalid value : '
                        . (get_debug_type($tag))
                    );
                }

                $span->setTag($k, $tag);
            }
        }

        if ($spanContext->isSampled() === true) {
            $this->spans[] = $span;
        }

        return $span;
    }

    public function inject(OTSpanContext $spanContext, string $format, &$carrier): void
    {
        if ($format === Formats\TEXT_MAP) {
            $this->propagator->inject($spanContext, $format, $carrier);
        } else {
            throw UnsupportedFormatException::forFormat($format);
        }
    }

    public function extract(string $format, $carrier): ?OTSpanContext
    {
        if ($format === Formats\TEXT_MAP) {
            return $this->propagator->extract($format, $carrier);
        }

        throw UnsupportedFormatException::forFormat($format);
    }

    public function getSpans(): array
    {
        return $this->spans;
    }

    public function reportSpan(): void
    {
        if ($this->spans) {
            $this->reporter->report($this);
            $this->spans = [];
        }
    }

    public function getScopeManager(): ScopeManager
    {
        return $this->scopeManager;
    }

    public function getActiveSpan(): ?OTSpan
    {
        $activeScope = $this->getScopeManager()->getActive();
        if ($activeScope === null) {
            return null;
        }

        return $activeScope->getSpan();
    }

    /**
     * @param array|StartSpanOptions $options
     *
     * @throws Exception
     */
    public function startActiveSpan(string $operationName, $options = []): Scope
    {
        if (!$options instanceof StartSpanOptions) {
            $options = StartSpanOptions::create($options);
        }

        $parentSpan = $this->getParentSpanContext($options);
        if ($parentSpan === null && ($activeSpan = $this->getActiveSpan()) !== null) {
            $parentContext = $activeSpan->getContext();
            $options       = $options->withParent($parentContext);
        }

        $span = $this->startSpan($operationName, $options);

        return $this->getScopeManager()->activate($span, $options->shouldFinishSpanOnClose());
    }

    private function getParentSpanContext(StartSpanOptions $options): ?SpanContext
    {
        $references = $options->getReferences();
        $parentSpan = null;

        foreach ($references as $ref) {
            $parentSpan = $ref->getSpanContext();
            if ($parentSpan instanceof SpanContext && $ref->isType(Reference::CHILD_OF)) {
                return $parentSpan;
            }
        }

        if ($parentSpan instanceof SpanContext
            && (
                $parentSpan->isValid()
                || (!$parentSpan->isTraceIdValid() && $parentSpan->debugId)
                || count($parentSpan->baggage) > 0
            )
        ) {
            return $parentSpan;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function getEnvTags(): array
    {
        $tags = [];
        if (isset($_SERVER['JAEGER_TAGS']) && $_SERVER['JAEGER_TAGS'] !== '') {
            $envTags = explode(',', (string)$_SERVER['JAEGER_TAGS']);

            foreach ($envTags as $envTag) {
                [$key, $value] = explode('=', $envTag);
                $tags[$key] = $value;
            }
        }

        return $tags;
    }

    public function gen128bit(): void
    {
        $this->gen128bit = true;
    }

    public function flush(): void
    {
        $this->reportSpan();
        $this->reporter->close();
    }

    /**
     * @throws Exception
     */
    private function generateId(): int
    {
        return (int)(microtime(true) * 10000 . random_int(10000, 99999));
    }
}
