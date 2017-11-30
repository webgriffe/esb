<?php

namespace Webgriffe\Esb;

use Webgriffe\Esb\Model\QueuedJob;

interface WorkerInterface
{
    /**
     * @return string
     */
    public function getTube(): string;

    /**
     * @return void
     */
    public function init();

    /**
     * @param QueuedJob $job
     * @return void
     */
    public function work(QueuedJob $job);

    /**
     * @return int
     */
    public function getReleaseDelay(): int;
}
