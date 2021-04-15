<?php

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
