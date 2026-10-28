<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Producer;

use Amp\Iterator;
use Amp\Producer;
use Amp\Promise;
use Amp\Success;
use Webgriffe\Esb\CrontabProducerInterface;
use Webgriffe\Esb\Model\Job;

class CleanOldJobs implements CrontabProducerInterface
{
    public function __construct(
        private readonly string $cronExpression,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function produce(mixed $data = null): Iterator
    {
        return new Producer(
            function (callable $emit): \Generator {
                yield $emit(new Job([]));
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function getCrontab(): string
    {
        return $this->cronExpression;
    }

    /**
     * @inheritDoc
     */
    public function init(): Promise
    {
        return new Success();
    }
}
