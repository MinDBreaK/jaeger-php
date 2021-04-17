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

namespace Jaeger\Transport;

use Exception;
use Jaeger\Jaeger;
use Jaeger\Thrift\AgentClient;
use Jaeger\Thrift\JaegerThriftSpan;
use Jaeger\Thrift\Process;
use Jaeger\Thrift\Span;
use Jaeger\Thrift\TStruct;
use Jaeger\UdpClient;
use JsonException;
use Thrift\Transport\TMemoryBuffer;
use Thrift\Protocol\TCompactProtocol;
use Jaeger\Constants;
use UnexpectedValueException;

class TransportUdp implements Transport
{

    private TMemoryBuffer $tran;

    public static string $hostPort = '';

    /*
     * sizeof(Span) * numSpans + processByteSize + emitBatchOverhead <= maxPacketSize
     */
    public static int $maxSpanBytes = 0;

    /**
     * @var array{thriftProcess: array, thriftSpans: array}[]
     */
    public static array $batchs = [];

    public TCompactProtocol $thriftProtocol;

    public int $procesSize = 0;

    public int $bufferSize = 0;

    public const MAC_UDP_MAX_SIZE = 9216;

    public function __construct(string $hostPort, int $maxPacketSize = 0)
    {
        if (!preg_match('/[a-zA-Z]+:\d{1,5}/', $hostPort)) {
            throw new UnexpectedValueException('Invalid Jaeger host:port');
        }

        self::$hostPort = $hostPort;

        if ($maxPacketSize <= 0) {
            $maxPacketSize = stripos(PHP_OS_FAMILY, 'Darwin') !== false
                ? self::MAC_UDP_MAX_SIZE
                : Constants\UDP_PACKET_MAX_LENGTH;
        }

        self::$maxSpanBytes = $maxPacketSize - Constants\EMIT_BATCH_OVER_HEAD;

        $this->tran           = new TMemoryBuffer();
        $this->thriftProtocol = new TCompactProtocol($this->tran);
    }

    /**
     * @throws JsonException
     */
    public function buildAndCalcSizeOfProcessThrift(Jaeger $jaeger): void
    {
        $jaeger->processThrift = (new JaegerThriftSpan())->buildJaegerProcessThrift($jaeger);
        $jaeger->process       = (new Process($jaeger->processThrift));
        $this->procesSize      = $this->getAndCalcSizeOfSerializedThrift($jaeger->process, $jaeger->processThrift);
        $this->bufferSize      += $this->procesSize;
    }

    /**
     * Collect tracking information to be sent
     *
     * @throws JsonException
     * @throws Exception
     */
    public function append(Jaeger $jaeger): bool
    {

        if ($jaeger->process === null) {
            $this->buildAndCalcSizeOfProcessThrift($jaeger);
        }

        $thriftSpansBuffer = [];  // Uncommitted span used to temporarily store shards

        foreach ($jaeger->spans as $span) {

            $spanThrift = (new JaegerThriftSpan())->buildJaegerSpanThrift($span);

            $agentSpan = Span::getInstance();
            $agentSpan->setThriftSpan($spanThrift);
            $spanSize = $this->getAndCalcSizeOfSerializedThrift($agentSpan, $spanThrift);

            if ($spanSize > self::$maxSpanBytes) {
                throw new UnexpectedValueException("Span is too large");
            }

            if ($this->bufferSize + $spanSize >= self::$maxSpanBytes) {
                self::$batchs[] = [
                    'thriftProcess' => $jaeger->processThrift,
                    'thriftSpans'   => $thriftSpansBuffer,
                ];
                $this->flush();
                $thriftSpansBuffer = [];  // Empty the temp buffer
            }

            $thriftSpansBuffer[] = $spanThrift;
            $this->bufferSize    += $spanSize;
        }

        if ($thriftSpansBuffer) {
            self::$batchs[] = [
                'thriftProcess' => $jaeger->processThrift,
                'thriftSpans'   => $thriftSpansBuffer,
            ];
            $this->flush();
        }

        return true;
    }

    public function resetBuffer(): void
    {
        $this->bufferSize = $this->procesSize;
        self::$batchs     = [];
    }

    /**
     * Get the serialized thrift and calculate the serialized thrift character length
     *
     * @psalm-param array<string, mixed> $serializedThrift
     */
    private function getAndCalcSizeOfSerializedThrift(TStruct $ts, array &$serializedThrift): int
    {

        $ts->write($this->thriftProtocol);
        $serThriftStrlen = (int)$this->tran->available();

        //Buf is cleared after acquisition
        $serializedThrift['wrote'] = $this->tran->read(Constants\UDP_PACKET_MAX_LENGTH);

        return $serThriftStrlen;
    }

    /**
     * @throws Exception
     */
    public function flush(): int
    {
        $batchNum = count(self::$batchs);
        if ($batchNum <= 0) {
            return 0;
        }

        $spanNum   = 0;
        $udpClient = new UdpClient(self::$hostPort, new AgentClient());

        foreach (self::$batchs as $batch) {
            $spanNum += count($batch['thriftSpans']);
            $udpClient->emitBatch($batch);
        }

        $udpClient->close();
        $this->resetBuffer();

        return $spanNum;
    }

    public function getBatchs(): array
    {
        return self::$batchs;
    }
}
