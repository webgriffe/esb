<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Loop;
use Monolog\Logger;
use Webgriffe\Esb\Model\FlowConfig;

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
     * @var WorkerInstance[]
     */
    private $workerInstances;
    /**
     * @var Logger
     */
    private $logger;

    public function __construct(
        FlowConfig $flowConfig,
        ProducerInstance $producerInstance,
        array $workerInstances,
        Logger $logger
    ) {
        $this->flowConfig = $flowConfig;
        $this->producerInstance = $producerInstance;
        $this->workerInstances = $workerInstances;
        $this->logger = $logger;
    }

    public function boot()
    {
        $this->logger->info(
            'Booting flow',
            ['flow' => $this->flowConfig->getDescription(), 'tube' => $this->flowConfig->getTube()]
        );
        Loop::defer(function () {
            yield $this->producerInstance->boot();
        });
        foreach ($this->workerInstances as $workerInstance) {
            Loop::defer(function () use ($workerInstance) {
                yield $workerInstance->boot();
            });
        }
    }
}
