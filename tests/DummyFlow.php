<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

class DummyFlow implements FlowInterface
{
    /**
     * @var ProducerInterface
     */
    private $producer;

    /**
     * @var WorkerInterface
     */
    private $worker;

    /**
     * @var string
     */
    private $tube;

    public function __construct(ProducerInterface $producer, WorkerInterface $worker, string $tube)
    {
        $this->producer = $producer;
        $this->worker = $worker;
        $this->tube = $tube;
    }

    /**
     * @return string
     */
    public function getTube(): string
    {
        return $this->tube;
    }

    /**
     * @return ProducerInterface
     */
    public function getProducer(): ProducerInterface
    {
        return $this->producer;
    }

    /**
     * @return WorkerInterface
     */
    public function getWorker(): WorkerInterface
    {
        return $this->worker;
    }
}
