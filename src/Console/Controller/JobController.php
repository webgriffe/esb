<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use function Amp\call;

/**
 * @internal
 */
class JobController extends AbstractController
{
    public function __invoke(Request $request, string $flow, string $jobId)
    {
        return call(function () use ($jobId, $flow, $request) {
            $job = yield $this->getElasticsearch()->fetchJob($jobId, $flow);

            $queryParams = [];
            parse_str($request->getUri()->getQuery(), $queryParams);
            $requeued = (bool)($queryParams['requeued'] ?? false);

            return new Response(
                Status::OK,
                [],
                $this->getTwig()->render('job.html.twig', ['flow' => $flow, 'job' => $job, 'requeued' => $requeued])
            );
        });
    }
}
