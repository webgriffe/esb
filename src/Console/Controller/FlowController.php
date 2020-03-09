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
            $foundJobs = [];
            $query = '';
            if (array_key_exists('query', $queryParams)) {
                $query = $queryParams['query'];
                $foundJobs = yield $this->findAllTubeJobsByQuery($flowCode, $query);
            }
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

    private function findAllTubeJobsByQuery(string $flowCode, string $query)
    {
        return call(function () use ($flowCode, $query) {
            $response = yield $this->getElasticsearchClient()->uriSearchOneIndex($flowCode, $query);
            $jobs = [];
            foreach ($response['hits']['hits'] as $rawJob) {
                $jobs[] = $rawJob['_source'];
            }
            return $jobs;
        });
    }
}
