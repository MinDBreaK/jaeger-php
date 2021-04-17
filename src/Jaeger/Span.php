<?php

/*
 * Copyright (c) 2019, The Jaeger Authors
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except
 * in compliance with the License. You may obtain a copy of the License at
 *
 * https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software distributed under the License
 * is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express
 * or implied. See the License for the specific language governing permissions and limitations under
 * the License.
 */

namespace Jaeger;

use DateTimeInterface;
use OpenTracing\Reference;

class Span implements \OpenTracing\Span
{
    private string $operationName;

    public int $startTime;

    public ?int $finishTime = null;

    public string $spanKind = '';

    public SpanContext $spanContext;

    public int $duration = 0;

    /**
     * @var array<array{timestamp: int, fields: array}>
     */
    public array $logs = [];

    /**
     * @var array<string, bool|float|int|string>
     */
    public array $tags = [];

    /**
     * @var Reference[]
     */
    public array $references = [];

    /**
     * @param Reference[] $references
     */
    public function __construct(
        string $operationName,
        SpanContext $spanContext,
        array $references,
        int $startTime = null
    ) {
        $this->operationName = $operationName;
        $this->startTime     = $startTime ?? $this->microtimeToInt();
        $this->spanContext   = $spanContext;
        $this->references    = $references;
    }

    public function getOperationName(): string
    {
        return $this->operationName;
    }

    public function getContext(): SpanContext
    {
        return $this->spanContext;
    }

    /**
     * @param int|float|DateTimeInterface|null $finishTime  if passing float or int
     *                                                      it should represent the timestamp (including as many
     *                                                      decimal places as you need)
     */
    public function finish($finishTime = null): void
    {
        if ($this->finishTime !== null) {
            @trigger_error('Span is already finished', E_USER_WARNING);
        }

        $this->finishTime = $finishTime ? $this->timedParamToInt($finishTime) : $this->microtimeToInt();

        /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
        $this->duration = $this->finishTime - $this->startTime;
    }

    public function overwriteOperationName(string $newOperationName): void
    {
        $this->operationName = $newOperationName;
    }

    /**
     * @param string                $key
     * @param bool|float|int|string $value
     */
    public function setTag(string $key, $value): void
    {
        $this->tags[$key] = $value;
    }

    /**
     * Adds a log record to the span
     *
     * @param array                            $fields [key => val]
     * @param DateTimeInterface|int|float|null $timestamp
     */
    public function log(array $fields = [], $timestamp = null): void
    {
        $log              = [];
        $log['timestamp'] = $timestamp ? $this->timedParamToInt($timestamp) : $this->microtimeToInt();
        $log['fields']    = $fields;
        $this->logs[]     = $log;
    }

    public function addBaggageItem(string $key, string $value): void
    {
        $this->log(
            [
                'event' => 'baggage',
                'key'   => $key,
                'value' => $value,
            ]
        );
    }

    public function getBaggageItem(string $key): ?string
    {
        return $this->spanContext->getBaggageItem($key);
    }

    private function microtimeToInt(): int
    {
        return (int)(microtime(true) * 1000000);
    }

    private function timedParamToInt(float|DateTimeInterface|int $time): int
    {
        if ($time instanceof DateTimeInterface) {
            return (int)$time->format('Uu');
        }

        return (int)$time;
    }
}
