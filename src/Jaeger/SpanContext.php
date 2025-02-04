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

class SpanContext implements \OpenTracing\SpanContext
{
    // traceID represents globally unique ID of the trace.
    // Usually generated as a random number.
    public int $traceIdLow = 0;

    public int $traceIdHigh = 0;

    // spanID represents span ID that must be unique within its trace,
    // but does not have to be globally unique.
    public string $spanId;

    // parentID refers to the ID of the parent span.
    // Should be 0 if the current span is a root span.
    public string $parentId = '0';

    // flags is a bitmap containing such bits as 'sampled' and 'debug'.
    public int $flags;

    /**
     * Distributed Context baggage. The is a snapshot in time.
     *
     * @var array<string, scalar>
     */
    public array $baggage;

    // debugID can be set to some correlation ID when the context is being
    // extracted from a TextMap carrier.
    public $debugId;

    /**
     * @param array<string, scalar> $baggage
     */
    public function __construct(string $spanId, string $parentId, int $flags, array $baggage = null, int $debugId = 0)
    {
        $this->spanId   = $spanId;
        $this->parentId = $parentId;
        $this->flags    = $flags;
        $this->baggage  = $baggage ?? [];
        $this->debugId  = $debugId;
    }

    public function getBaggageItem(string $key): ?string
    {
        return isset($this->baggage[$key]) ? (string) $this->baggage[$key] : null;
    }

    public function withBaggageItem(string $key, string $value): \OpenTracing\SpanContext
    {
        $this->baggage[$key] = $value;

        return $this;
    }

    public function getIterator()
    {
        // TODO: Implement getIterator() method.
    }

    public function buildString(): string
    {
        if ($this->traceIdHigh) {
            return sprintf(
                "%x%016x:%x:%x:%x",
                $this->traceIdHigh,
                $this->traceIdLow,
                $this->spanId,
                $this->parentId,
                $this->flags
            );
        }

        return sprintf("%x:%x:%x:%x", $this->traceIdLow, $this->spanId, $this->parentId, $this->flags);
    }

    public function spanIdToString(): string
    {
        return sprintf("%x", $this->spanId);
    }

    public function parentIdToString(): string
    {
        return sprintf("%x", $this->parentId);
    }

    public function traceIdLowToString(): string
    {
        if ($this->traceIdHigh) {
            return sprintf("%x%016x", $this->traceIdHigh, $this->traceIdLow);
        }

        return sprintf("%x", $this->traceIdLow);
    }

    public function flagsToString(): string
    {
        return sprintf("%x", $this->flags);
    }

    public function isSampled(): bool
    {
        return $this->flags > 0;
    }

    public function hexToSignedInt($hex): int
    {
        //Avoid pure Arabic numerals eg:1
        if (!is_string($hex)) {
            $hex .= '';
        }

        $hexStrLen = strlen($hex);
        $dec       = 0;
        for ($i = 0; $i < $hexStrLen; $i++) {
            $hexByteStr = $hex[$i];
            if (ctype_xdigit($hexByteStr)) {
                $decByte = hexdec($hex[$i]);
                $dec     = ($dec << 4) | $decByte;
            }
        }

        return $dec;
    }

    public function traceIdToString($traceId): void
    {
        $len = strlen($traceId);
        if ($len > 16) {
            $this->traceIdHigh = $this->hexToSignedInt(substr($traceId, 0, 16));
            $this->traceIdLow  = $this->hexToSignedInt(substr($traceId, 16));
        } else {
            $this->traceIdLow = $this->hexToSignedInt($traceId);
        }
    }

    /**
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->isTraceIdValid() && $this->spanId;
    }

    /**
     * @return bool
     */
    public function isTraceIdValid(): bool
    {
        return $this->traceIdLow || $this->traceIdHigh;
    }
}
