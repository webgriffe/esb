<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Loop;

class Flow
{
    /**
     * @var ProducerInstance
     */
    private $producerInstance;
    /**
     * @var WorkerInstance[]
     */
    private $workerInstances;

    public function __construct(ProducerInstance $producerInstance, array $workerInstances)
    {
        $this->producerInstance = $producerInstance;
        $this->workerInstances = $workerInstances;
    }

    public function boot()
    {
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
