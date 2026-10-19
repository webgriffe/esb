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
     * @param array<WorkerInstanceInterface> $workerInstances
     */
    public function __construct(
        private readonly FlowConfig $flowConfig,
        private readonly ProducerInstance $producerInstance,
        private readonly array $workerInstances,
        private readonly LoggerInterface $logger
    ) {
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
