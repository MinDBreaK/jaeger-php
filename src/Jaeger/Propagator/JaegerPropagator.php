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

namespace Jaeger\Propagator;

use Jaeger\Constants;
use OpenTracing\SpanContext;

class JaegerPropagator implements Propagator
{
    public function inject(SpanContext $spanContext, $format, &$carrier): void
    {
        assert($spanContext instanceof \Jaeger\SpanContext);

        $carrier[strtoupper(Constants\Tracer_State_Header_Name)] = $spanContext->buildString();
        if ($spanContext->baggage) {
            foreach ($spanContext->baggage as $k => $v) {
                $carrier[strtoupper(Constants\Trace_Baggage_Header_Prefix . $k)] = $v;
            }
        }
    }

    public function extract(string $format, array $carrier): ?SpanContext
    {
        $spanContext = null;

        $carrier = array_change_key_case($carrier, CASE_LOWER);

        foreach ($carrier as $k => $v) {
            if (
                !in_array(
                    $k,
                    [
                        Constants\Tracer_State_Header_Name,
                        Constants\Jaeger_Debug_Header,
                        Constants\Jaeger_Baggage_Header,
                    ],
                    true
                )
                && stripos($k, Constants\Trace_Baggage_Header_Prefix) === false) {
                continue;
            }

            if ($spanContext === null) {
                $spanContext = new \Jaeger\SpanContext('0', '0', 0, null, 0);
            }

            if (is_array($v)) {
                $v = urldecode(current($v));
            } else {
                $v = urldecode((string)$v);
            }

            if ($k === Constants\Tracer_State_Header_Name) {
                [$traceId, $spanId, $parentId, $flags] = explode(':', $v);

                $spanContext->spanId   = (string)$spanContext->hexToSignedInt($spanId);
                $spanContext->parentId = (string)$spanContext->hexToSignedInt($parentId);
                $spanContext->flags    = (int)$flags;
                $spanContext->traceIdToString($traceId);
            } elseif (stripos($k, Constants\Trace_Baggage_Header_Prefix) !== false) {
                $safeKey = str_replace(Constants\Trace_Baggage_Header_Prefix, "", $k);
                if ($safeKey !== "") {
                    $spanContext->withBaggageItem($safeKey, $v);
                }
            } elseif ($k === Constants\Jaeger_Debug_Header) {
                $spanContext->debugId = $v;
            } elseif ($k === Constants\Jaeger_Baggage_Header) {
                // Converts a comma separated key value pair list into a map
                // e.g. key1=value1, key2=value2, key3 = value3
                // is converted to array { "key1" : "value1",
                //                                     "key2" : "value2",
                //                                     "key3" : "value3" }
                $parseVal = explode(',', $v);
                foreach ($parseVal as $val) {
                    if (str_contains($v, '=')) {
                        $kv = explode('=', trim($val));

                        if (count($kv) === 2) {
                            $spanContext->withBaggageItem($kv[0], $kv[1]);
                        }
                    }
                }
            }
        }


        return $spanContext;
    }

}
