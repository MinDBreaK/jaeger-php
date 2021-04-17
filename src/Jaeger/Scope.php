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

class Scope implements \OpenTracing\Scope
{

    /**
     * @var MockScopeManager
     */
    private $scopeManager = null;

    /**
     * @var span
     */
    private $span = null;

    /**
     * @var bool
     */
    private $finishSpanOnClose;


    /**
     * Scope constructor.
     * @param ScopeManager $scopeManager
     * @param \OpenTracing\Span $span
     * @param bool $finishSpanOnClose
     */
    public function __construct(ScopeManager $scopeManager, \OpenTracing\Span $span, $finishSpanOnClose)
    {
        $this->scopeManager = $scopeManager;
        $this->span = $span;
        $this->finishSpanOnClose = $finishSpanOnClose;
    }


    public function close()
    {
        if ($this->finishSpanOnClose) {
            $this->span->finish();
        }

        $this->scopeManager->delActive($this);
    }


    public function getSpan()
    {
        return $this->span;
    }
}
