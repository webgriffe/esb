<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use function Amp\call;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Promise;

/**
 * @internal
 */
class JobController extends AbstractController
{
    /**
     * @return Promise<Response>
     */
    public function __invoke(Request $request, string $flow, string $jobId): Promise
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
