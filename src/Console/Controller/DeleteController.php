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
class DeleteController extends AbstractController
{
    public function __invoke(Request $request, string $jobId)
    {
        return call(function () use ($jobId) {
            /** @var Job $stats */
            $stats = yield $this->getBeanstalkClient()->getJobStats((int)$jobId);
            yield $this->getBeanstalkClient()->delete((int)$jobId);
            return new Response(Status::MOVED_PERMANENTLY, ['Location' => "/tube/{$stats->tube}"]);
        });
    }
}
