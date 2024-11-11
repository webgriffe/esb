<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Loop;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Model\FlowConfig;

/**
 * @internal
 */
class Flow
{
    /**
     * @var FlowConfig
     */
    private $flowConfig;

    /**
     * @var ProducerInstance
     */
    private $producerInstance;

    /**
     * @var WorkerInstanceInterface[]
     */
    private $workerInstances;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param FlowConfig $flowConfig
     * @param ProducerInstance $producerInstance
     * @param array<WorkerInstanceInterface> $workerInstances
     * @param LoggerInterface $logger
     */
    public function __construct(
        FlowConfig $flowConfig,
        ProducerInstance $producerInstance,
        array $workerInstances,
        LoggerInterface $logger
    ) {
        $this->flowConfig = $flowConfig;
        $this->producerInstance = $producerInstance;
        $this->workerInstances = $workerInstances;
        $this->logger = $logger;
    }

    public function boot(): void
    {
        Loop::defer(function () {
            $this->logger->info(
                'Booting flow',
                ['flow' => $this->flowConfig->getDescription(), 'tube' => $this->flowConfig->getTube()]
            );

            yield $this->producerInstance->boot();
            foreach ($this->workerInstances as $workerInstance) {
                yield $workerInstance->boot();
            }
        });
    }

    public function getCode(): string
    {
        return $this->flowConfig->getTube();
    }

    public function getDescription(): string
    {
        return $this->flowConfig->getDescription();
    }

    public function getProducerClassName(): string
    {
        return get_class($this->producerInstance->getProducer());
    }

    public function getWorkerClassName(): string
    {
        $workerInstance = $this->workerInstances[0];
        return get_class($workerInstance->getWorker());
    }
}
