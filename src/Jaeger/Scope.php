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

use OpenTracing\ScopeManager as ScopeManagerInterface;
use OpenTracing\Span as SpanInterface;

class Scope implements \OpenTracing\Scope
{
    private ScopeManager $scopeManager;

    private SpanInterface $span;

    private bool $finishSpanOnClose;

    public function __construct(ScopeManager $scopeManager, SpanInterface $span, bool $finishSpanOnClose = ScopeManagerInterface::DEFAULT_FINISH_SPAN_ON_CLOSE)
    {
        $this->scopeManager      = $scopeManager;
        $this->span              = $span;
        $this->finishSpanOnClose = $finishSpanOnClose;
    }

    public function close(): void
    {
        if ($this->finishSpanOnClose) {
            $this->span->finish();
        }

        $this->scopeManager->delActive($this);
    }

    public function getSpan(): SpanInterface
    {
        return $this->span;
    }
}
