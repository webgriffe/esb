<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Promise;
use Webgriffe\Esb\Model\JobInterface;

interface WorkerInterface
{
    /**
     * @return Promise
     */
    public function init(): Promise;

    /**
     * @param JobInterface $job
     * @return Promise
     */
    public function work(JobInterface $job): Promise;
}
