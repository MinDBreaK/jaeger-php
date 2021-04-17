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

use Exception;
use Jaeger\Thrift\AgentClient;
use LogicException;
use Socket;

/**
 * send thrift to jaeger-agent
 * Class UdpClient
 *
 * @package Jaeger
 */
class UdpClient
{
    private string $host;

    private int $port;

    /**
     * @var resource|Socket|false
     */
    private $socket;

    private AgentClient $agentClient;

    public function __construct(string $hostPost, AgentClient $agentClient)
    {
        [$this->host, $port] = explode(":", $hostPost);
        $this->port        = (int)$port;
        $this->agentClient = $agentClient;
        $this->socket      = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    }

    public function isOpen(): bool
    {
        return $this->socket !== false;
    }

    /**
     * send thrift
     *
     * @param array{thriftProcess: array, thriftSpans: array} $batch
     *
     * @return bool
     * @throws LogicException
     * @throws Exception
     */
    public function emitBatch(array $batch): bool
    {
        if ($this->socket === false) {
            throw new LogicException("Cannot emit batch. Socket is not opened.");
        }

        $buildThrift = $this->agentClient->buildThrift($batch);

        if (isset($buildThrift['len']) && $buildThrift['len'] && $this->isOpen()) {
            $len        = $buildThrift['len'];
            $enitThrift = $buildThrift['thriftStr'];

            /** @psalm-suppress PossiblyInvalidArgument */
            $res = socket_sendto($this->socket, $enitThrift, $len, 0, $this->host, $this->port);

            if ($res === false) {
                throw new Exception("Thrift emit failed");
            }

            return true;
        }

        return false;
    }

    public function close(): void
    {
        if ($this->socket === false) {
            throw new LogicException('Cannot close non-opened socket');
        }

        /** @psalm-suppress PossiblyInvalidArgument */
        socket_close($this->socket);

        $this->socket = false;
    }
}
