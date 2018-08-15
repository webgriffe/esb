<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

interface FlowInterface
{
    /**
     * @return string
     */
    public function getTube(): string;

    /**
     * @return ProducerInterface
     */
    public function getProducer(): ProducerInterface;

    /**
     * @return WorkerInterface
     */
    public function getWorker(): WorkerInterface;
}
