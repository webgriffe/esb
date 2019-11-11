<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Promise;
use Amp\Success;
use Webgriffe\Esb\Model\JobInterface;
use function Amp\call;

final class AlwaysFailingWorker implements WorkerInterface
{
    /**
     * @return Promise
     */
    public function init(): Promise
    {
        return new Success(null);
    }

    /**
     * {@inheritDoc}
     */
    public function work(JobInterface $job): Promise
    {
        return call(function () {
            throw new \Error('Failed!');
        });
    }
}
