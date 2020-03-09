<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Beanstalk\NotFoundException;
use Amp\Beanstalk\Stats\Job;
use Amp\Beanstalk\Stats\System;
use Amp\Beanstalk\Stats\Tube;
use Amp\Http\Server\Request;
use Twig\Environment;
use Webgriffe\AmpElasticsearch\Client;
use function Amp\call;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Promise;

/**
 * @internal
 */
class FlowController extends AbstractController
{
    public function __invoke(Request $request, string $flowCode): Promise
    {
        return call(function () use ($request, $flowCode) {
            $queryParams = [];
            parse_str($request->getUri()->getQuery(), $queryParams);
            $query = $queryParams['query'] ?? '';
            $from = (int) ($queryParams['from'] ?? 0);
            $foundJobs = yield $this->findAllTubeJobsByQuery($flowCode, $query, $from);
            return new Response(
                Status::OK,
                [],
                $this->getTwig()->render(
                    'flow.html.twig',
                    [
                        'flowCode' => $flowCode,
                        'foundJobs' => $foundJobs,
                        'query' => $query,
                    ]
                )
            );
        });
    }

    private function findAllTubeJobsByQuery(string $flowCode, string $query, int $from): Promise
    {
        return call(function () use ($flowCode, $query, $from) {
            $response = yield $this->getElasticsearchClient()->uriSearchOneIndex(
                $flowCode,
                $query,
                [
                    'sort' => 'lastEvent.time:desc',
                    'from' => $from
                ]
            );
            $jobs = [];
            foreach ($response['hits']['hits'] as $rawJob) {
                $jobs[] = $rawJob['_source'];
            }
            return $jobs;
        });
    }
}
