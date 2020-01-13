<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\NotFoundException;
use Amp\Beanstalk\Stats\Job;
use Amp\Beanstalk\Stats\System;
use Amp\Beanstalk\Stats\Tube;
use function Amp\call;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Promise;

/**
 * @internal
 */
class TubeController extends AbstractController
{
    public function __invoke(string $tube): Promise
    {
        return call(function () use ($tube) {
            /** @var Tube $tube */
            $tube = yield $this->getBeanstalkClient()->getTubeStats($tube);
            $queryParams = [];
            parse_str($this->getRequest()->getUri()->getQuery(), $queryParams);
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
                    'tube.html.twig',
                    [
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
            /** @var System $stats */
            $stats = yield $this->getBeanstalkClient()->getSystemStats();
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
                    $stats = yield $this->getBeanstalkClient()->getJobStats($id);
                    if ($stats->tube !== $tube) {
                        throw new \RuntimeException('Not the right tube, skip.');
                    }
                    $payload = yield $this->getBeanstalkClient()->peek($id);
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
