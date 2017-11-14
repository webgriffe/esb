<?php

namespace Webgriffe\Esb\Service\Producer;

interface ProducerInterface
{
    /**
     * @return string
     */
    public function getTube(): string;

    /**
     * @return string[]
     */
    public function produce(): array;

    /**
     * @param string $payload
     * @return void
     */
    public function onProduceSuccess(string $payload);

    /**
     * @param string $payload
     * @param \Exception $exception
     * @return void
     */
    public function onProduceFail(string $payload, \Exception $exception);
}
