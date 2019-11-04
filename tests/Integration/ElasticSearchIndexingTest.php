<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use Amp\Promise;
use org\bovigo\vfs\vfsStream;
use Symfony\Component\Serializer\Serializer;
use Webgriffe\Esb\DummyFilesystemRepeatProducer;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\KernelTestCase;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\ProducedJobEvent;
use Webgriffe\Esb\Model\ReservedJobEvent;
use Webgriffe\Esb\Service\ElasticSearch;
use Webgriffe\Esb\TestUtils;
use function Amp\File\exists;

class ElasticSearchIndexingTest extends KernelTestCase
{
    const TUBE = 'sample_tube';

    use TestUtils;

    /**
     * @test
     */
    public function itIndexJobOntoElasticSearch()
    {
        $producerDir = vfsStream::url('root/producer_dir');
        $workerFile = vfsStream::url('root/worker.data');
        self::createKernel([
            'services' => [
                DummyFilesystemRepeatProducer::class => ['arguments' => [$producerDir]],
                DummyFilesystemWorker::class => ['arguments' => [$workerFile]],
            ],
            'flows' => [
                self::TUBE => [
                    'description' => 'Repeat Flow',
                    'producer' => ['service' => DummyFilesystemRepeatProducer::class],
                    'worker' => ['service' => DummyFilesystemWorker::class],
                ]
            ]
        ]);
        mkdir($producerDir);
        Loop::delay(
            200,
            function () use ($producerDir) {
                touch($producerDir . DIRECTORY_SEPARATOR . 'job1');
                Loop::delay(
                    200,
                    function () use ($producerDir) {
                        touch($producerDir . DIRECTORY_SEPARATOR . 'job2');
                    }
                );
            }
        );
        $this->stopWhen(function () use ($workerFile) {
            return (yield exists($workerFile)) && count($this->getFileLines($workerFile)) === 2;
        });
        self::$kernel->boot();

        $search = Promise\wait($this->esClient->uriSearchOneIndex(ElasticSearch::INDEX_NAME, ''));
        $this->assertCount(2, $search['hits']['hits']);

        $this->assertForEachJob(
            function (Job $job) {
                $events = $job->getEvents();
                $this->assertCount(2, $events);
                /** @var ProducedJobEvent $event */
                $event = $events[0];
                $this->assertInstanceOf(ProducedJobEvent::class, $event);
                $this->assertEquals(DummyFilesystemRepeatProducer::class, $event->getProducerFqcn());
                /** @var ReservedJobEvent $event */
                $event = $events[1];
                $this->assertInstanceOf(ReservedJobEvent::class, $event);
                $this->assertEquals(DummyFilesystemWorker::class, $event->getWorkerFqcn());
            },
            $search['hits']['hits']
        );
    }

    private function assertForEachJob(callable $callable, array $jobsData)
    {
        /** @var Serializer $serializer */
        $serializer = self::$kernel->getContainer()->get('serializer');
        foreach ($jobsData as $jobData) {
            $jobData = $jobData['_source'];
            /** @var Job $job */
            $job = $serializer->denormalize($jobData, Job::class);
            $callable($job);
        }
    }
}
