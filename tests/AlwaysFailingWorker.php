<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use function Amp\call;
use Amp\Promise;
use Amp\Success;
use Webgriffe\Esb\Model\QueuedJob;

class AlwaysFailingWorker implements WorkerInterface
{
    const TUBE = 'sample_tube';

    /**
     * @return string
     */
    public function getTube(): string
    {
        return self::TUBE;
    }

    /**
     * @return Promise
     */
    public function init(): Promise
    {
        return new Success(null);
    }

    /**
     * @param QueuedJob $job
     * @return Promise
     */
    public function work(QueuedJob $job): Promise
    {
        return call(function () {
            throw new \Error('Failed!');
        });
    }

    /**
     * @return int
     */
    public function getReleaseDelay(): int
    {
        return 0;
    }

    /**
     * @return int
     */
    public function getInstancesCount(): int
    {
        return 1;
    }
}
