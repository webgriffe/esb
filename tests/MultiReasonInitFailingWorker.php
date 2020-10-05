<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Promise;
use Amp\Success;
use Webgriffe\Esb\Model\JobInterface;
use function Amp\call;

final class MultiReasonInitFailingWorker implements WorkerInterface
{
    /**
     * @return Promise
     */
    public function init(): Promise
    {
        return Promise\some([
            call(function () { throw new \Exception('Exception number one'); } ),
            call(function () { throw new \Exception('Exception number two'); } ),
        ], 2);
    }

    /**
     * {@inheritDoc}
     */
    public function work(JobInterface $job): Promise
    {
        return new Success();
    }
}
