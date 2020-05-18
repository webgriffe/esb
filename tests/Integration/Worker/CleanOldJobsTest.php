<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Integration\Worker;

use Webgriffe\Esb\Exception\ElasticSearch\JobNotFoundException;
use Webgriffe\Esb\KernelTestCase;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\WorkedJobEvent;
use Webgriffe\Esb\Service\ElasticSearch;
use Webgriffe\Esb\Worker\CleanOldJobs;
use function Amp\Promise\wait;

class CleanOldJobsTest extends KernelTestCase
{
    const INDEX_NAME = 'clean_old_jobs_test';

    /**
     * @test
     */
    public function itDoesNothingIfThereAreNoOldJobs()
    {
        self::createKernel([]);
        $container = self::$kernel->getContainer();

        /** @var ElasticSearch $esService */
        $esService = $container->get(ElasticSearch::class);
        $oldJob = new Job([]);
        $oldJob->addEvent(new WorkedJobEvent(new \DateTime('-5 days'), 'worker'));
        wait($esService->indexJob($oldJob, self::INDEX_NAME));

        $worker = new CleanOldJobs($esService->getClient(), 30);
        wait($worker->work(new Job([])));

        $this->assertNotNull(wait($esService->fetchJob($oldJob->getUuid(), self::INDEX_NAME)));
    }

    /**
     * @test
     */
    public function itDeletesOldJobs()
    {
        self::createKernel([]);
        $container = self::$kernel->getContainer();

        /** @var ElasticSearch $esService */
        $esService = $container->get(ElasticSearch::class);
        $oldJob = new Job([]);
        $oldJob->addEvent(new WorkedJobEvent(new \DateTime('-35 days'), 'worker'));
        wait($esService->indexJob($oldJob, self::INDEX_NAME));
        wait($esService->getClient()->refresh());

        $worker = new CleanOldJobs($esService->getClient(), 30);
        wait($worker->work(new Job([])));

        $this->expectException(JobNotFoundException::class);
        wait($esService->getClient()->refresh());
        wait($esService->fetchJob($oldJob->getUuid(), self::INDEX_NAME));
    }
}
