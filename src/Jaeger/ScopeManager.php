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

use OpenTracing\Scope;
use OpenTracing\Span;

class ScopeManager implements \OpenTracing\ScopeManager
{
    private array $scopes = [];

    /**
     * append scope
     *
     * @param \OpenTracing\Span $span
     * @param bool              $finishSpanOnClose
     *
     * @return Scope
     */
    public function activate(Span $span, bool $finishSpanOnClose = self::DEFAULT_FINISH_SPAN_ON_CLOSE): Scope
    {
        $scope          = new \Jaeger\Scope($this, $span, $finishSpanOnClose);
        $this->scopes[] = $scope;

        return $scope;
    }

    /**
     * get last scope
     *
     * @return mixed|null
     */
    public function getActive(): ?Scope
    {
        if (empty($this->scopes)) {
            return null;
        }

        return $this->scopes[count($this->scopes) - 1];
    }

    /**
     * del scope
     *
     * @param Scope $scope
     *
     * @return bool
     */
    public function delActive(Scope $scope): bool
    {
        $scopeLength = count($this->scopes);

        if ($scopeLength <= 0) {
            return false;
        }

        for ($i = 0; $i < $scopeLength; $i++) {
            if ($scope === $this->scopes[$i]) {
                array_splice($this->scopes, $i, 1);
            }
        }

        return true;
    }
}
