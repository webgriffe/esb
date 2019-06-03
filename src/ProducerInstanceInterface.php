<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Promise;

interface ProducerInstanceInterface
{
    public function boot(): Promise;

    public function produceAndQueueJobs($data = null): Promise;

    public function getProducer(): ProducerInterface;
}
