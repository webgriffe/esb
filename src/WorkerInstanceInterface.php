<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Promise;

interface WorkerInstanceInterface
{
    /**
     * @return Promise
     */
    public function boot(): Promise;

    /**
     * @return WorkerInterface
     */
    public function getWorker(): WorkerInterface;
}
