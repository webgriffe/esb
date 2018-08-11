<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\Stats\Job;
use Amp\Beanstalk\Stats\System;
use Amp\Beanstalk\Stats\Tube;
use function Amp\call;
use Amp\Promise;
use RingCentral\Psr7\Response;

class TubeController
{
    use ControllerTrait;

    public function __invoke(string $tube): Promise
    {
        return call(function () use ($tube) {
            /** @var Tube $tube */
            $tube = yield $this->beanstalkClient->getTubeStats($tube);
            $queryParams = $this->request->getQueryParams();
            $foundJobs = [];
            $query = '';
            if (array_key_exists('query', $queryParams)) {
                $query = $queryParams['query'];
                $foundJobs = yield $this->findAllTubeJobsByQuery($tube->name, $query);
            }
            return new Response(
                200,
                [],
                $this->twig->render('tube.html.twig', ['tube' => $tube, 'foundJobs' => $foundJobs, 'query' => $query])
            );
        });
    }

    private function findAllTubeJobsByQuery(string $tube, string $query)
    {
        return call(function () use ($tube, $query) {
            /** @var System $stats */
            $stats = yield $this->beanstalkClient->getSystemStats();
            $ready = $stats->currentJobsReady;
            $reserved = $stats->currentJobsReserved;
            $delayed = $stats->currentJobsDelayed;
            $buried = $stats->currentJobsBuried;
            $deleted = $stats->cmdDelete;
            $maxJobId = $ready + $reserved + $delayed + $buried + $deleted;
            $jobs = [];
            for ($id = 0; $id <= $maxJobId; $id++) {
                $jobs[$id] = call(function () use ($id, $tube, $query) {
                    /** @var Job $stats */
                    $stats = yield $this->beanstalkClient->getJobStats($id);
                    if ($stats->tube !== $tube) {
                        throw new \RuntimeException('Not the right tube, skip.');
                    }
                    $payload = yield $this->beanstalkClient->peek($id);
                    if (stripos($payload, $query) === false) {
                        throw new \RuntimeException('Not matching the query, skip.');
                    }
                    return ['stats' => $stats, 'payload' => $payload];
                });
            }

            list(, $jobs) = yield Promise\any($jobs);
            return $jobs;
        });
    }
}
