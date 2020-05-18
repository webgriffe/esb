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
    /**
     * @var string
     */
    private $cronExpression;

    /**
     * CleanOldElasticsearchDocuments constructor.
     * @param string $cronExpression
     */
    public function __construct(string $cronExpression)
    {
        $this->cronExpression = $cronExpression;
    }

    /**
     * @inheritDoc
     */
    public function produce($data = null): Iterator
    {
        return new Producer(
            function (callable $emit) {
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
