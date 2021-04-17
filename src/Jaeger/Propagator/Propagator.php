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

use OpenTracing\SpanContext;

interface Propagator
{

    /**
     * @param SpanContext           $spanContext
     * @param string                $format
     * @param array<string, scalar> $carrier
     */
    public function inject(SpanContext $spanContext, string $format, array &$carrier): void;

    /**
     * @param string                $format
     * @param array<string, scalar|string[]> $carrier
     *
     * @return SpanContext|null
     */
    public function extract(string $format, array $carrier): ?SpanContext;

}
