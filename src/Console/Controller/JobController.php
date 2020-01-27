<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\Stats\Job;
use Amp\Http\Server\Request;
use function Amp\call;
use Amp\Http\Server\Response;
use Amp\Http\Status;

/**
 * @internal
 */
class JobController extends AbstractController
{
    public function __invoke(Request $request, string $jobId)
    {
        return call(function () use ($jobId) {
            /** @var Job $stats */
            $stats = yield $this->getBeanstalkClient()->getJobStats((int)$jobId);
            $payload = yield $this->getBeanstalkClient()->peek((int)$jobId);
            if (@unserialize($payload) !== false) {
                $payload = print_r(unserialize($payload), true);
            }
            return new Response(
                Status::OK,
                [],
                $this->getTwig()->render('job.html.twig', ['stats' => $stats, 'payload' => $payload])
            );
        });
    }
}
