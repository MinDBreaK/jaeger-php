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

namespace Jaeger\Thrift;

use Thrift\Exception\TException;
use Thrift\Exception\TTransportException;
use Thrift\Protocol\TProtocol;
use Thrift\Type\TType;

class Process implements TStruct{

    public static ?TProtocol $tptl = null;

    public static string $serverName = '';

    /**
     * @var array<array-key, array{key: string, vStr: string, vType: string}>
     */
    public static array $thriftTags = [];

    public static string $wrote;

    /**
     * @param array{
     *     serverName: string,
     *     tags: array<array-key, array{key: string, vStr: string, vType: string}>,
     *     wrote?: string
     * } $processThrift
     */
    public function __construct(array $processThrift)
    {
        self::$serverName = $processThrift['serverName'] ?? '';
        self::$thriftTags = $processThrift['tags'] ?? [];
        self::$wrote      = $processThrift['wrote'] ?? '';
    }

    /**
     * @throws TTransportException
     * @throws TException
     */
    public function write(TProtocol $t): bool
    {
        self::$tptl = $t;
        if (self::$wrote) {
            $tran = self::$tptl->getTransport();
            $tran->write(self::$wrote);
        } else {

            self::$tptl->writeStructBegin("Process");

            $this->handleProcessSName();
            $this->handleProcessTags();

            self::$tptl->writeFieldStop();
            self::$tptl->writeStructEnd();
        }

        return true;
    }


    private function handleProcessSName(): void
    {
        /** @psalm-suppress PossiblyNullReference */
        self::$tptl->writeFieldBegin("serverName", TType::STRING, 1);

        self::$tptl->writeString(self::$serverName);

        self::$tptl->writeFieldEnd();
    }


    private function handleProcessTags(): void
    {
        if(count(self::$thriftTags) > 0) {
            /** @psalm-suppress PossiblyNullReference */
            self::$tptl->writeFieldBegin("tags", TType::LST, 2);
            self::$tptl->writeListBegin(TType::STRUCT, count(self::$thriftTags));

            $tagsObj = Tags::getInstance();
            $tagsObj->setThriftTags(self::$thriftTags);
            $tagsObj->write(self::$tptl);

            self::$tptl->writeListEnd();
            self::$tptl->writeFieldEnd();
        }
    }

    public function read(TProtocol $t): void
    {
    }
}
