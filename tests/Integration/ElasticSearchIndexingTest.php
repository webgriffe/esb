<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use Amp\Promise;
use org\bovigo\vfs\vfsStream;
use Symfony\Component\Serializer\Serializer;
use Webgriffe\Esb\AlwaysFailingWorker;
use Webgriffe\Esb\DummyFilesystemRepeatProducer;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\KernelTestCase;
use Webgriffe\Esb\Model\ErroredJobEvent;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\ProducedJobEvent;
use Webgriffe\Esb\Model\ReservedJobEvent;
use Webgriffe\Esb\Model\WorkedJobEvent;
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
    public function itIndexSuccessfulJobsOntoElasticSearchWithAllEvents()
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
        $this->stopWhen(function () {
            $successLog = array_filter(
                $this->logHandler()->getRecords(),
                function ($log) {
                    return strpos($log['message'], 'Successfully worked a Job') !== false;
                }
            );
            return count($successLog) >= 2;
        });
        self::$kernel->boot();

        Promise\wait($this->esClient->refresh());
        $search = Promise\wait($this->esClient->uriSearchOneIndex(self::TUBE, ''));
        $this->assertCount(2, $search['hits']['hits']);
        $this->assertForEachJob(
            function (Job $job) {
                $events = $job->getEvents();
                $this->assertCount(3, $events);
                /** @var ProducedJobEvent $event */
                $event = $events[0];
                $this->assertInstanceOf(ProducedJobEvent::class, $event);
                $this->assertEquals(DummyFilesystemRepeatProducer::class, $event->getProducerFqcn());
                /** @var ReservedJobEvent $event */
                $event = $events[1];
                $this->assertInstanceOf(ReservedJobEvent::class, $event);
                $this->assertEquals(DummyFilesystemWorker::class, $event->getWorkerFqcn());
                /** @var WorkedJobEvent $event */
                $event = $events[2];
                $this->assertInstanceOf(WorkedJobEvent::class, $event);
                $this->assertEquals(DummyFilesystemWorker::class, $event->getWorkerFqcn());
                $this->assertEquals($event, $job->getLastEvent());
            },
            $search['hits']['hits']
        );
    }

    /**
     * @test
     */
    public function itIndexErroredJobsOntoElasticSearchWithErrorEvents()
    {
        $producerDir = vfsStream::url('root/producer_dir');
        self::createKernel([
            'services' => [
                DummyFilesystemRepeatProducer::class => ['arguments' => [$producerDir]],
                AlwaysFailingWorker::class => [],
            ],
            'flows' => [
                self::TUBE => [
                    'description' => 'Always Failing Flow',
                    'producer' => ['service' => DummyFilesystemRepeatProducer::class],
                    'worker' => ['service' => AlwaysFailingWorker::class],
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
        $this->stopWhen(function () {
            $buryLog = array_filter(
                $this->logHandler()->getRecords(),
                function ($log) {
                    return strpos($log['message'], 'A Job reached maximum work retry limit') !== false;
                }
            );
            return count($buryLog) >= 2;
        });

        self::$kernel->boot();

        Promise\wait($this->esClient->refresh());
        $search = Promise\wait($this->esClient->uriSearchOneIndex(self::TUBE, ''));
        $this->assertCount(2, $search['hits']['hits']);
        $this->assertForEachJob(
            function (Job $job) {
                $events = $job->getEvents();
                $this->assertCount(11, $events);
                /** @var ProducedJobEvent $event */
                $event = $events[0];
                $this->assertInstanceOf(ProducedJobEvent::class, $event);
                $this->assertEquals(DummyFilesystemRepeatProducer::class, $event->getProducerFqcn());
                /** @var ReservedJobEvent $event */
                $event = $events[1];
                $this->assertInstanceOf(ReservedJobEvent::class, $event);
                $this->assertEquals(AlwaysFailingWorker::class, $event->getWorkerFqcn());
                /** @var ErroredJobEvent $event */
                $event = $events[2];
                $this->assertInstanceOf(ErroredJobEvent::class, $event);
                $this->assertEquals(AlwaysFailingWorker::class, $event->getWorkerFqcn());
                $this->assertEquals('Failed!', $event->getErrorMessage());
                /** @var ReservedJobEvent $event */
                $event = $events[3];
                $this->assertInstanceOf(ReservedJobEvent::class, $event);
                $this->assertEquals(AlwaysFailingWorker::class, $event->getWorkerFqcn());
                /** @var ErroredJobEvent $lastEvent */
                $lastEvent = $job->getLastEvent();
                $this->assertInstanceOf(ErroredJobEvent::class, $lastEvent);
                $this->assertEquals(AlwaysFailingWorker::class, $lastEvent->getWorkerFqcn());
                $this->assertEquals('Failed!', $lastEvent->getErrorMessage());
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
