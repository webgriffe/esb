<?php

namespace Webgriffe\Esb;

use Amp\Loop;
use Amp\Promise;
use Amp\Deferred;
use Amp\Success;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Model\QueuedJob;
use function Amp\call;

class DummyLongInitWorker implements WorkerInterface
{
    /**
     * @var mixed
     */
    private $asyncResult;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * DummyLongInitWorker constructor.
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return string
     */
    public function getTube(): string
    {
        return 'sample_tube';
    }

    /**
     * @return Promise
     */
    public function init(): Promise
    {
       return call(function() {
           $this->logger->info('Starting async job in long init worker...');
           $this->asyncResult = yield $this->asyncJob();
           $this->logger->info('Async job done in long init worker, result is: ' . $this->asyncResult);
       });
    }

    /**
     * @param QueuedJob $job
     * @return Promise
     * @throws \Error
     */
    public function work(QueuedJob $job): Promise
    {
        return new Success(null);
    }

    /**
     * @return int
     */
    public function getReleaseDelay(): int
    {
        return 1;
    }

    /**
     * @return int
     */
    public function getInstancesCount(): int
    {
        return 1;
    }

    private function asyncJob(): Promise
    {
        $deferred = new Deferred();
        Loop::delay($msDelay = 200, function () use ($deferred) {
            $deferred->resolve('done');
        });
        return $deferred->promise();
    }
}
