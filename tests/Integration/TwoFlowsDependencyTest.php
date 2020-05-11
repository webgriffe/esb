<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\DummyFilesystemRepeatProducer;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\KernelTestCase;
use Webgriffe\Esb\TestUtils;

class TwoFlowsDependencyTest extends KernelTestCase
{
    private const FLOW1_CODE = 'two_flows_flow1';
    private const FLOW2_CODE = 'two_flows_flow2';

    use TestUtils;

    public function testTwoFlowsWithDependencies()
    {
        $producerDir1 = vfsStream::url('root/producer_dir_1');
        $workerFile1 = vfsStream::url('root/worker_1.data');
        $producerDir2 = vfsStream::url('root/producer_dir_2');
        $workerFile2 = vfsStream::url('root/worker_2.data');

        self::createKernel([
            'services' => [
                'producer1' => ['class' => DummyFilesystemRepeatProducer::class, 'arguments' => [$producerDir1]],
                'worker1' => ['class' => DummyFilesystemWorker::class,'arguments' => [$workerFile1]],

                'producer2' => ['class' => DummyFilesystemRepeatProducer::class, 'arguments' => [$producerDir2]],
                'worker2' => ['class' => DummyFilesystemWorker::class,'arguments' => [$workerFile2]],
            ],
            'flows' => [
                self::FLOW1_CODE => [
                    'description' => 'Two Flows Test Flow 1',
                    'producer' => ['service' => 'producer1'],
                    'worker' => ['service' => 'worker1'],
                ],
                self::FLOW2_CODE => [
                    'description' => 'Two Flows Test Flow 2',
                    'producer' => ['service' => 'producer2'],
                    'worker' => ['service' => 'worker2'],
                ]
            ]
        ]);

        Loop::delay(
            200,
            function () use ($producerDir1, $producerDir2) {
                touch($producerDir1 . DIRECTORY_SEPARATOR . 'job1');
                touch($producerDir2 . DIRECTORY_SEPARATOR . 'job2');
                Loop::delay(200, function () {
                    Loop::stop();
                });
            }
        );

        self::$kernel->boot();

        $this->assertReadyJobsCountInTube(0, self::FLOW1_CODE);
        $workerFileLines = $this->getFileLines($workerFile1);
        $this->assertCount(1, $workerFileLines);
        $worker1Line = $workerFileLines[0];
        $this->assertContains('job1', $worker1Line);
        $matches = [];
        $this->assertTrue((bool)preg_match('/^(\d+) (\d+).*/', $worker1Line, $matches));
        $timestamp1 = $matches[1];
        $timestamp1 += ((float)$matches[2]) / 1000000;


        $this->assertReadyJobsCountInTube(0, self::FLOW2_CODE);
        $workerFileLines = $this->getFileLines($workerFile2);
        $this->assertCount(1, $workerFileLines);
        $worker2Line = $workerFileLines[0];
        $this->assertContains('job2', $worker2Line);
        $this->assertTrue((bool)preg_match('/^(\d+) (\d+).*/', $worker2Line, $matches));
        $timestamp2 = $matches[1];
        $timestamp2 += ((float)$matches[2]) / 1000000;

        //This is hard to read, but it checks that $timestamp1 >= $timestamp2
        $this->assertGreaterThanOrEqual($timestamp2, $timestamp1);
    }
}
