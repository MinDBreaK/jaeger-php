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

namespace Jaeger\Thrift;

use Jaeger\Jaeger;
use Jaeger\Span;
use Jaeger\SpanContext;
use JsonException;
use OpenTracing\Reference;

class JaegerThriftSpan
{

    /**
     * @param Jaeger $jaeger
     *
     * @return array{serverName: string, tags: array}
     * @throws JsonException
     */
    public function buildJaegerProcessThrift(Jaeger $jaeger): array
    {
        $tags              = [];
        $tags['peer.ipv4'] = (string)($_SERVER['SERVER_ADDR'] ?? '0.0.0.0');
        $tags['peer.port'] = (string)($_SERVER['SERVER_PORT'] ?? '80');

        $tags    = array_merge($tags, $jaeger->tags);
        $tagsObj = Tags::getInstance();
        $tagsObj->setTags($tags);

        $thriftTags = $tagsObj->buildTags();

        return [
            'serverName' => $jaeger->serverName,
            'tags'       => $thriftTags,
        ];
    }

    /**
     * @param Span $span
     *
     * @return array{
     *     traceIdLow: int,
     *     traceIdHigh: int,
     *     spanId: string,
     *     parentSpanId: string,
     *     operationName: string,
     *     flags: int,
     *     startTime: int,
     *     duration: int,
     *     tags: array,
     *     logs: array,
     *     references: array
     * }
     *
     * @throws JsonException
     */
    public function buildJaegerSpanThrift(Span $span): array
    {
        $spContext = $span->spanContext;

        return [
            'traceIdLow'    => $spContext->traceIdLow,
            'traceIdHigh'   => $spContext->traceIdHigh,
            'spanId'        => $spContext->spanId,
            'parentSpanId'  => $spContext->parentId,
            'operationName' => $span->getOperationName(),
            'flags'         => $spContext->flags,
            'startTime'     => $span->startTime,
            'duration'      => $span->duration,
            'tags'          => $this->buildTags($span->tags),
            'logs'          => $this->buildLogs($span->logs),
            'references'    => $this->buildReferences($span->references),
        ];
    }

    /**
     * @param array<string, scalar|array> $tags
     *
     * @throws JsonException
     */
    private function buildTags(array $tags): array
    {
        $tagsObj = Tags::getInstance();
        $tagsObj->setTags($tags);

        return $tagsObj->buildTags();
    }

    /**
     * @param array{fields: array<string, array<array-key, mixed>|scalar>, timestamp: int}[] $logs
     *
     * @throws JsonException
     */
    private function buildLogs(array $logs): array
    {
        $resultLogs = [];
        $tagsObj    = Tags::getInstance();

        foreach ($logs as $log) {
            $tagsObj->setTags($log['fields']);
            $fields       = $tagsObj->buildTags();
            $resultLogs[] = [
                "timestamp" => $log['timestamp'],
                "fields"    => $fields,
            ];
        }

        return $resultLogs;
    }

    /**
     * @param Reference[] $references
     *
     * @return array{refType: string|null, traceIdLow: int, traceIdHigh: int, spanId: string}[]
     */
    private function buildReferences(array $references): array
    {
        $spanRef = [];
        foreach ($references as $ref) {
            if ($ref->isType(Reference::CHILD_OF)) {
                $type = SpanRefType::CHILD_OF;
            } elseif ($ref->isType(Reference::FOLLOWS_FROM)) {
                $type = SpanRefType::FOLLOWS_FROM;
            }

            $ctx = $ref->getSpanContext();

            assert($ctx instanceof SpanContext);

            $spanRef[] = [
                'refType'     => $type ?? null,
                'traceIdLow'  => $ctx->traceIdLow,
                'traceIdHigh' => $ctx->traceIdHigh,
                'spanId'      => $ctx->spanId,
            ];
        }

        return $spanRef;
    }
}
