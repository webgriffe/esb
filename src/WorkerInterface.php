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
     * @param QueuedJob $job
     * @return void
     */
    public function work(QueuedJob $job);
}
