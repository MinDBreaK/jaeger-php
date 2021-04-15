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

use Jaeger\Sampler\Sampler;
use OpenTracing\Scope;
use OpenTracing\ScopeManager;
use OpenTracing\Span;
use OpenTracing\SpanContext;
use OpenTracing\Formats;
use OpenTracing\Tracer;
use Jaeger\Reporter\Reporter;
use OpenTracing\StartSpanOptions;
use OpenTracing\Reference;
use Jaeger\Propagator\Propagator;
use OpenTracing\UnsupportedFormatException;

class Jaeger implements Tracer
{

    private ?Reporter $reporter = null;

    private ?Sampler $sampler = null;

    private bool $gen128bit = false;

    private ScopeManager $scopeManager;

    public array $spans = [];

    public array $tags = [];

    public $process = null;

    public mixed $serverName = '';

    public mixed $processThrift = '';

    /** @var Propagator|null */
    public ?Propagator $propagator = null;

    public function __construct(
        $serverName = '',
        Reporter $reporter,
        Sampler $sampler,
        ScopeManager $scopeManager
    ) {

        $this->reporter = $reporter;

        $this->sampler = $sampler;

        $this->scopeManager = $scopeManager;

        $this->setTags($this->sampler->getTags());
        $this->setTags($this->getEnvTags());

        if ($serverName === '') {
            $this->serverName = $_SERVER['SERVER_NAME'] ?? 'unknow server';
        } else {
            $this->serverName = $serverName;
        }
    }

    /**
     * @param array $tags key => value
     */
    public function setTags(array $tags = []): void
    {
        if (!empty($tags)) {
            $this->tags = array_merge($this->tags, $tags);
        }
    }

    /**
     * init span info
     *
     * @param string $operationName
     * @param array  $options
     *
     * @return Span
     */
    public function startSpan(string $operationName, $options = []): Span
    {

        if (!($options instanceof StartSpanOptions)) {
            $options = StartSpanOptions::create($options);
        }

        $parentSpan = $this->getParentSpanContext($options);
        if ($parentSpan === null || !$parentSpan->traceIdLow) {
            $low                     = $this->generateId();
            $spanId                  = $low;
            $flags                   = $this->sampler->IsSampled();
            $spanContext             = new \Jaeger\SpanContext($spanId, 0, $flags, null, 0);
            $spanContext->traceIdLow = $low;
            if ($this->gen128bit === true) {
                $spanContext->traceIdHigh = $this->generateId();
            }
        } else {
            $spanContext             = new \Jaeger\SpanContext(
                $this->generateId(),
                $parentSpan->spanId, $parentSpan->flags, $parentSpan->baggage, 0
            );
            $spanContext->traceIdLow = $parentSpan->traceIdLow;
            if ($parentSpan->traceIdHigh) {
                $spanContext->traceIdHigh = $parentSpan->traceIdHigh;
            }
        }

        $startTime = $options->getStartTime() ? (int)($options->getStartTime() * 1000000) : null;
        $span      = new \Jaeger\Span($operationName, $spanContext, $options->getReferences(), $startTime);
        if (!empty($options->getTags())) {
            foreach ($options->getTags() as $k => $tag) {
                $span->setTag($k, $tag);
            }
        }
        if ($spanContext->isSampled() === true) {
            $this->spans[] = $span;
        }

        return $span;
    }

    public function setPropagator(Propagator $propagator): void
    {
        $this->propagator = $propagator;
    }

    /**
     * 注入
     *
     * @param SpanContext $spanContext
     * @param string      $format
     * @param             $carrier
     */
    public function inject(SpanContext $spanContext, string $format, &$carrier): void
    {
        if ($format == Formats\TEXT_MAP) {
            $this->propagator->inject($spanContext, $format, $carrier);
        } else {
            throw UnsupportedFormat::forFormat($format);
        }
    }

    /**
     * 提取
     *
     * @param string $format
     * @param        $carrier
     */
    public function extract(string $format, $carrier): ?SpanContext
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

    public function getActiveSpan(): ?Span
    {
        $activeScope = $this->getScopeManager()->getActive();
        if ($activeScope === null) {
            return null;
        }

        return $activeScope->getSpan();
    }

    public function startActiveSpan(string $operationName, $options = []): Scope
    {
        if (!$options instanceof StartSpanOptions) {
            $options = StartSpanOptions::create($options);
        }

        $parentSpan = $this->getParentSpanContext($options);
        if ($parentSpan === null && $this->getActiveSpan() !== null) {
            $parentContext = $this->getActiveSpan()->getContext();
            $options       = $options->withParent($parentContext);
        }

        $span = $this->startSpan($operationName, $options);

        return $this->getScopeManager()->activate($span, $options->shouldFinishSpanOnClose());
    }

    private function getParentSpanContext(StartSpanOptions $options)
    {
        $references = $options->getReferences();

        $parentSpan = null;

        foreach ($references as $ref) {
            $parentSpan = $ref->getContext();
            if ($ref->isType(Reference::CHILD_OF)) {
                return $parentSpan;
            }
        }

        if ($parentSpan && (
                $parentSpan->isValid()
                || (!$parentSpan->isTraceIdValid() && $parentSpan->debugId)
                || count($parentSpan->baggage) > 0)
        ) {
            return $parentSpan;
        }

        return null;
    }

    public function getEnvTags(): array
    {
        $tags = [];
        if (isset($_SERVER['JAEGER_TAGS']) && $_SERVER['JAEGER_TAGS'] !== '') {
            $envTags = explode(',', $_SERVER['JAEGER_TAGS']);
            foreach ($envTags as $envK => $envTag) {
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

    private function generateId(): string
    {
        return microtime(true) * 10000 . rand(10000, 99999);
    }
}
