<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Promise;
use Webgriffe\Esb\Model\JobInterface;

interface WorkerInterface
{
    /** @return Promise<null> */
    public function init(): Promise;

    /** @return Promise<null> */
    public function work(JobInterface $job): Promise;
}
