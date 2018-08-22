<?php

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\DummyFilesystemRepeatProducer;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\KernelTestCase;
use Webgriffe\Esb\TestUtils;

class RepeatProducerAndWorkerTest extends KernelTestCase
{
    const TUBE = 'sample_tube';

    use TestUtils;

    public function testRepeatProducerAndWorkerTogether()
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
                        Loop::delay(200, function () {
                            Loop::stop();
                        });
                    }
                );
            }
        );

        self::$kernel->boot();

        $workerFileLines = $this->getFileLines($workerFile);
        $this->assertCount(2, $workerFileLines);
        $this->assertContains('job1', $workerFileLines[0]);
        $this->assertContains('job2', $workerFileLines[1]);
        $this->assertReadyJobsCountInTube(0, self::TUBE);
    }

    public function testLongProduceRepeatProducerDoesNotOverlapProduceInvokations()
    {
        $producerInterval = 50;
        $produceDelay = 200; // The producer is invoked every 50ms but it takes 200ms to produce every Job
        $producerDir = vfsStream::url('root/producer_dir');
        $workerFile = vfsStream::url('root/worker.data');
        self::createKernel([
            'services' => [
                DummyFilesystemRepeatProducer::class => [
                    'arguments' => [
                        $producerDir,
                        $producerInterval,
                        $produceDelay
                    ]
                ],
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
        touch($producerDir . DIRECTORY_SEPARATOR . 'job1');
        touch($producerDir . DIRECTORY_SEPARATOR . 'job2');
        Loop::delay(1000, function () {
            Loop::stop();
        });

        self::$kernel->boot();

        $workerFileLines = $this->getFileLines($workerFile);
        $this->assertCount(2, $workerFileLines);
        $this->assertContains('job1', $workerFileLines[0]);
        $this->assertContains('job2', $workerFileLines[1]);
        $this->assertReadyJobsCountInTube(0, self::TUBE);
    }
}
