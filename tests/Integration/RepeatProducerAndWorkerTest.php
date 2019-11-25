<?php

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\DummyFilesystemRepeatProducer;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\KernelTestCase;
use Webgriffe\Esb\TestUtils;
use function Amp\File\exists;

class RepeatProducerAndWorkerTest extends KernelTestCase
{
    private const FLOW_CODE = 'repeat_producer_and_worker_flow';

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
                self::FLOW_CODE => [
                    'description' => 'Repeat Producer and Worker Test Flow',
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

        $workerFileLines = $this->getFileLines($workerFile);
        $this->assertOneArrayEntryContains('job1', $workerFileLines);
        $this->assertOneArrayEntryContains('job2', $workerFileLines);
        $this->assertReadyJobsCountInTube(0, self::FLOW_CODE);
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
                self::FLOW_CODE => [
                    'description' => 'Repeat Flow',
                    'producer' => ['service' => DummyFilesystemRepeatProducer::class],
                    'worker' => ['service' => DummyFilesystemWorker::class],
                ]
            ]
        ]);
        mkdir($producerDir);
        touch($producerDir . DIRECTORY_SEPARATOR . 'job1');
        touch($producerDir . DIRECTORY_SEPARATOR . 'job2');
        $this->stopWhen(function () use ($workerFile) {
            return (yield exists($workerFile)) && count($this->getFileLines($workerFile)) === 2;
        });

        self::$kernel->boot();

        $workerFileLines = $this->getFileLines($workerFile);
        $this->assertContains('job1', $workerFileLines[0]);
        $this->assertContains('job2', $workerFileLines[1]);
        $this->assertReadyJobsCountInTube(0, self::FLOW_CODE);
    }

    private function assertOneArrayEntryContains(string $expected, array $array): void
    {
        foreach ($array as $item) {
            if (strpos($item, $expected) !== false) {
                return;
            }
        }
        $this->fail(sprintf('Failed asserting that array has one entry that contains "%s".', $expected));
    }
}
