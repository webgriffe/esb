<?php

namespace Webgriffe\Esb;

use Amp\Promise;
use Webgriffe\Esb\Model\QueuedJob;

interface WorkerInterface
{
    /**
     * @return string
     */
    public function getTube(): string;

    /**
     * @return Promise
     */
    public function init(): Promise;

    /**
     * @param QueuedJob $job
     * @return Promise
     */
    public function work(QueuedJob $job): Promise;

    /**
     * @return int
     */
    public function getReleaseDelay(): int;

    /**
     * @return int
     */
    public function getInstancesCount(): int;
}
