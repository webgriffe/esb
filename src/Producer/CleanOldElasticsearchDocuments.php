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
     * @inheritDoc
     */
    public function getCrontab(): string
    {
        // TODO: Implement getCrontab() method.
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
        return new Success();
    }
}
