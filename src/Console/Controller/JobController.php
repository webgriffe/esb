<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Twig\Environment;
use Webgriffe\AmpElasticsearch\Client;
use function Amp\call;

/**
 * @internal
 */
class JobController extends AbstractController
{
    public function __invoke(Request $request, string $flow, string $jobId)
    {
        return call(function () use ($jobId, $flow, $request) {
            $result = yield $this->getElasticsearchClient()->search(
                ['term' => ['uuid.keyword' => ['value' => $jobId]]],
                $flow
            );
            $document = $result['hits']['hits'][0];
            $job = $document['_source'];

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
