<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Promise;

interface ProducerInstanceInterface
{
    /**
     * @return Promise<null>
     */
    public function boot(): Promise;

    /**
     * @return Promise<int>
     */
    public function produceAndQueueJobs(mixed $data = null): Promise;

    public function getProducer(): ProducerInterface;
}
