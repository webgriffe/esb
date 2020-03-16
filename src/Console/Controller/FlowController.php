<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Beanstalk\NotFoundException;
use Amp\Beanstalk\Stats\Job;
use Amp\Beanstalk\Stats\System;
use Amp\Beanstalk\Stats\Tube;
use Amp\Http\Server\Request;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
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
            $page = (int)($queryParams['page'] ?? '1');
            $foundJobs = yield $this->findAllTubeJobsByQuery($flowCode, $query);
            $adapter = new ArrayAdapter($foundJobs);
            $pager = new Pagerfanta($adapter);
            $pager->setMaxPerPage(5);
            $pager->setCurrentPage($page);
            return new Response(
                Status::OK,
                [],
                $this->getTwig()->render(
                    'flow.html.twig',
                    [
                        'flowCode' => $flowCode,
                        'pager' => $pager,
                        'query' => $query,
                    ]
                )
            );
        });
    }

    private function findAllTubeJobsByQuery(string $flowCode, string $query): Promise
    {
        return call(function () use ($flowCode, $query) {
            $response = yield $this->getElasticsearchClient()->uriSearchOneIndex(
                $flowCode,
                $query,
                ['sort' => 'lastEvent.time:desc']
            );
            $jobs = [];
            foreach ($response['hits']['hits'] as $rawJob) {
                $jobs[] = $rawJob['_source'];
            }
            return $jobs;
        });
    }
}
