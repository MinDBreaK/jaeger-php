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

use Jaeger\Reporter\RemoteReporter;
use Jaeger\Reporter\Reporter;
use Jaeger\Transport\TransportUdp;
use OpenTracing\NoopTracer;
use Jaeger\Sampler\Sampler;
use Jaeger\Sampler\ConstSampler;
use Jaeger\Propagator\JaegerPropagator;
use Jaeger\Propagator\ZipkinPropagator;
use const Jaeger\Constants\PROPAGATOR_JAEGER;
use const Jaeger\Constants\PROPAGATOR_ZIPKIN;

class Config {

    private $transport = null;

    private $reporter = null;

    private $sampler = null;

    private $scopeManager = null;

    private bool $gen128bit = false;

    public static $tracer = null;

    public static $span = null;

    public static ?Config $instance = null;

    public static bool $disabled = false;

    public static string $propagator = PROPAGATOR_JAEGER;


    private function __construct(){

    }


    private function __clone(){

    }


    public static function getInstance(): self
    {
        if(! (self::$instance instanceof self) )
        {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function initTracer(string $serverName, string $agentHostPort = ''): Jaeger
    {

        if(self::$disabled){
            return NoopTracer::create();
        }

        if($serverName === ''){
            throw new \UnexpectedValueException("serverName require");
        }

        if(isset(self::$tracer[$serverName]) && !empty(self::$tracer[$serverName])){
            return self::$tracer[$serverName];
        }


        if($this->transport == null){
            $this->transport = new TransportUdp($agentHostPort);
        }

        if($this->reporter == null) {
            $this->reporter = new RemoteReporter($this->transport);
        }

        if($this->sampler == null){
            $this->sampler = new ConstSampler(true);
        }

        if($this->scopeManager == null){
            $this->scopeManager = new ScopeManager();
        }


        if(self::$propagator === PROPAGATOR_ZIPKIN){
            $propagator = new ZipkinPropagator();
        }else{
            $propagator = new JaegerPropagator();
        }

        $tracer = new Jaeger($serverName, $this->reporter, $this->sampler, $this->scopeManager, $propagator);

        if($this->gen128bit == true){
            $tracer->gen128bit();
        }


        self::$tracer[$serverName] = $tracer;


        return $tracer;
    }


    /**
     * close tracer
     * @param $disabled
     */
    public function setDisabled($disabled){
        self::$disabled = $disabled;

        return $this;
    }


    public function setTransport(Transport\Transport $transport){
        $this->transport = $transport;

        return $this;
    }


    public function setReporter(Reporter $reporter){
        $this->reporter = $reporter;

        return $this;
    }


    public function setSampler(Sampler $sampler): self
    {
        $this->sampler = $sampler;

        return $this;
    }


    public function gen128bit(): self
    {
        $this->gen128bit = true;

        return $this;
    }


    public function flush(): bool
    {
        if(count(self::$tracer) > 0) {
            foreach(self::$tracer as $tracer){
                $tracer->reportSpan();
            }
            $this->reporter->close();
        }

        return true;
    }
}
