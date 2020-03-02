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
    /**
     * @var Client
     */
    private $elasticSearchClient;

    public function __construct(
        Environment $twig,
        BeanstalkClient $beanstalkClient,
        Client $elasticSearchClient
    ) {
        parent::__construct($twig, $beanstalkClient);
        $this->elasticSearchClient = $elasticSearchClient;
    }

    public function __invoke(Request $request, string $flow): Promise
    {
        return call(function () use ($request, $flow) {
            /** @var Tube $tube */
            $tube = yield $this->getBeanstalkClient()->getTubeStats($flow);
            $queryParams = [];
            parse_str($request->getUri()->getQuery(), $queryParams);
            $foundJobs = [];
            $query = '';
            if (array_key_exists('query', $queryParams)) {
                $query = $queryParams['query'];
                $foundJobs = yield $this->findAllTubeJobsByQuery($tube->name, $query);
            }
            return new Response(
                Status::OK,
                [],
                $this->getTwig()->render(
                    'flow.html.twig',
                    [
                        'flow' => $flow,
                        'tube' => $tube,
                        'foundJobs' => $foundJobs,
                        'query' => $query,
                        'peeks' => yield $this->getTubePeeks($tube->name),
                    ]
                )
            );
        });
    }

    private function findAllTubeJobsByQuery(string $tube, string $query)
    {
        return call(function () use ($tube, $query) {
            $response = yield $this->elasticSearchClient->uriSearchOneIndex($tube, $query);
            $jobs = [];
            foreach ($response['hits']['hits'] as $rawJob) {
                $jobs[] = $rawJob['_source'];
            }
            return $jobs;
        });
    }

    private function getTubePeeks(string $tube): Promise
    {
        return call(function () use ($tube) {
            yield $this->getBeanstalkClient()->use($tube);
            try {
                $peekReady = yield $this->getBeanstalkClient()->peekReady();
            } catch (NotFoundException $e) {
                $peekReady = false;
            }
            try {
                $peekDelayed = yield $this->getBeanstalkClient()->peekDelayed();
            } catch (NotFoundException $e) {
                $peekDelayed = false;
            }
            try {
                $peekBuried = yield $this->getBeanstalkClient()->peekBuried();
            } catch (NotFoundException $e) {
                $peekBuried = false;
            }
            $peeks = ['ready' => $peekReady, 'delayed' => $peekDelayed, 'buried' => $peekBuried];
            foreach ($peeks as $state => $peek) {
                if ($peek !== false && @unserialize($peek) !== false) {
                    $peeks[$state] = print_r(unserialize($peek), true);
                }
            }
            return $peeks;
        });
    }
}
