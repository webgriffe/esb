<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\Stats\Job;
use function Amp\call;
use RingCentral\Psr7\Response;

class DeleteController
{
    use ControllerTrait;

    public function __invoke(string $jobId)
    {
        return call(function () use ($jobId) {
            /** @var Job $stats */
            $stats = yield $this->beanstalkClient->getJobStats((int)$jobId);
            yield $this->beanstalkClient->delete((int)$jobId);
            return new Response(301, ['Location' => "/tube/{$stats->tube}"]);
        });
    }
}
