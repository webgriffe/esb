<?php

namespace Webgriffe\Esb\Service\Worker;

use Webgriffe\Esb\Model\Job;

interface WorkerInterface
{
    /**
     * @return string
     */
    public function getTube(): string;

    /**
     * @param Job $job
     * @return void
     */
    public function work(Job $job);
}
