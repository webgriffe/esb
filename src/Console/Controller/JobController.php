<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\Stats\Job;
use function Amp\call;
use Amp\Http\Server\Response;
use Amp\Http\Status;

/**
 * @internal
 */
class JobController
{
    use ControllerTrait;

    public function __invoke(string $jobId)
    {
        return call(function () use ($jobId) {
            /** @var Job $stats */
            $stats = yield $this->beanstalkClient->getJobStats((int)$jobId);
            $payload = yield $this->beanstalkClient->peek((int)$jobId);
            if (@unserialize($payload) !== false) {
                $payload = print_r(unserialize($payload), true);
            }
            return new Response(
                Status::OK,
                [],
                $this->twig->render('job.html.twig', ['stats' => $stats, 'payload' => $payload])
            );
        });
    }
}
