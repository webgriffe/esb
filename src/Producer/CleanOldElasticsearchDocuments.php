<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Producer;

use Amp\Iterator;
use Amp\Promise;
use Amp\Success;
use Webgriffe\Esb\CrontabProducerInterface;

class CleanOldElasticsearchDocuments implements CrontabProducerInterface
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

    /**
     * @inheritDoc
     */
    public function produce($data = null): Iterator
    {
        // TODO: Implement produce() method.
    }
}
