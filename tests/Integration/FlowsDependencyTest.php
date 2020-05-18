<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\DummyFilesystemRepeatProducer;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\KernelTestCase;
use Webgriffe\Esb\TestUtils;
use \Exception;

class FlowsDependencyTest extends KernelTestCase
{
    private const FLOW1_CODE = 'two_flows_flow1';
    private const FLOW2_CODE = 'two_flows_flow2';
    private const FLOW3_CODE = 'two_flows_flow3';

    use TestUtils;

    /**
     * Flow 1 depends on flow 2, which in turn depends on flow 3. Flow 3 does nothing in this test, its only purpose is
     * to make sure that flow 2 depends on something in turn so that the initial "producer delay" is applied both to
     * flow 1 and to flow 2 in the same way.
     *
     * @throws Exception
     */
    public function testTwoFlowsWithDependencies()
    {
        $producerDir1 = vfsStream::url('root/producer_dir_1');
        $workerFile1 = vfsStream::url('root/worker_1.data');
        $producerDir2 = vfsStream::url('root/producer_dir_2');
        $workerFile2 = vfsStream::url('root/worker_2.data');
        $producerDir3 = vfsStream::url('root/producer_dir_3');
        $workerFile3 = vfsStream::url('root/worker_3.data');

        self::createKernel([
            'services' => [
                'producer1' => ['class' => DummyFilesystemRepeatProducer::class, 'arguments' => [$producerDir1]],
                'worker1' => ['class' => DummyFilesystemWorker::class,'arguments' => [$workerFile1]],

                'producer2' => ['class' => DummyFilesystemRepeatProducer::class, 'arguments' => [$producerDir2]],
                'worker2' => ['class' => DummyFilesystemWorker::class,'arguments' => [$workerFile2]],

                'producer3' => ['class' => DummyFilesystemRepeatProducer::class, 'arguments' => [$producerDir3]],
                'worker3' => ['class' => DummyFilesystemWorker::class,'arguments' => [$workerFile3]],
            ],
            'flows' => [
                self::FLOW1_CODE => [
                    'description' => 'Two Flows Test Flow 1',
                    'producer' => ['service' => 'producer1'],
                    'worker' => ['service' => 'worker1'],
                    'depends_on' => [self::FLOW2_CODE],
                ],
                self::FLOW2_CODE => [
                    'description' => 'Two Flows Test Flow 2',
                    'producer' => ['service' => 'producer2'],
                    'worker' => ['service' => 'worker2'],
                    'depends_on' => [self::FLOW3_CODE],
                ],
                self::FLOW3_CODE => [
                    'description' => 'Two Flows Test Flow 3',
                    'producer' => ['service' => 'producer3'],
                    'worker' => ['service' => 'worker3'],
                ]
            ]
        ]);

        mkdir($producerDir1);
        mkdir($producerDir2);
        mkdir($producerDir3);

        Loop::delay(
            200,
            function () use ($producerDir1, $producerDir2) {
                touch($producerDir1 . DIRECTORY_SEPARATOR . 'job1');
                touch($producerDir2 . DIRECTORY_SEPARATOR . 'job2');
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

        $this->assertReadyJobsCountInTube(0, self::FLOW1_CODE);
        $workerFileLines = $this->getFileLines($workerFile1);
        $this->assertCount(1, $workerFileLines);
        $worker1Line = $workerFileLines[0];
        $this->assertContains('job1', $worker1Line);
        $timestamp1 = $this->getLogLineTimestamp($worker1Line);

        $this->assertReadyJobsCountInTube(0, self::FLOW2_CODE);
        $workerFileLines = $this->getFileLines($workerFile2);
        $this->assertCount(1, $workerFileLines);
        $worker2Line = $workerFileLines[0];
        $this->assertContains('job2', $worker2Line);
        $timestamp2 = $this->getLogLineTimestamp($worker2Line);

        $this->assertReadyJobsCountInTube(0, self::FLOW3_CODE);
        $this->assertFileNotExists($workerFile3);

        //This is hard to read, but it checks that $timestamp1 >= $timestamp2
        $this->assertGreaterThanOrEqual(
            $timestamp2,
            $timestamp1,
            "Job 1 ({$timestamp1}) was worked before job 2 ({$timestamp2}), ".
            'but they should have been executed in the reverse order.'
        );
    }

    /**
     * Flow 1 depends on flow 2 which depends on flow 3. If flow 3 has to process a long running job, both flows 1 and 2
     * must wait patiently.
     * Notice that this ONLY happens if all 3 flows have ready jobs at the same time. If flow 1 and flow 3 have ready
     * jobs but flow 2 is idle, then flow 1 will not wait and it will work its jobs immediately.
     *
     * @throws Exception
     */
    public function testDependencyQueue()
    {
        $producerDir1 = vfsStream::url('root/producer_dir_1');
        $workerFile1 = vfsStream::url('root/worker_1.data');
        $producerDir2 = vfsStream::url('root/producer_dir_2');
        $workerFile2 = vfsStream::url('root/worker_2.data');
        $producerDir3 = vfsStream::url('root/producer_dir_3');
        $workerFile3 = vfsStream::url('root/worker_3.data');

        //Flow 3 is slow
        self::createKernel([
            'services' => [
                'producer1' => ['class' => DummyFilesystemRepeatProducer::class, 'arguments' => [$producerDir1]],
                'worker1' => ['class' => DummyFilesystemWorker::class,'arguments' => [$workerFile1]],

                'producer2' => ['class' => DummyFilesystemRepeatProducer::class, 'arguments' => [$producerDir2]],
                'worker2' => ['class' => DummyFilesystemWorker::class,'arguments' => [$workerFile2]],

                'producer3' => ['class' => DummyFilesystemRepeatProducer::class, 'arguments' => [$producerDir3]],
                'worker3' => ['class' => DummyFilesystemWorker::class,'arguments' => [$workerFile3, 3]],
            ],
            'flows' => [
                self::FLOW1_CODE => [
                    'description' => 'Two Flows Test Flow 1',
                    'producer' => ['service' => 'producer1'],
                    'worker' => ['service' => 'worker1'],
                    'depends_on' => [self::FLOW2_CODE],
                ],
                self::FLOW2_CODE => [
                    'description' => 'Two Flows Test Flow 2',
                    'producer' => ['service' => 'producer2'],
                    'worker' => ['service' => 'worker2'],
                    'depends_on' => [self::FLOW3_CODE],
                ],
                self::FLOW3_CODE => [
                    'description' => 'Two Flows Test Flow 3',
                    'producer' => ['service' => 'producer3'],
                    'worker' => ['service' => 'worker3'],
                ]
            ]
        ]);

        mkdir($producerDir1);
        mkdir($producerDir2);
        mkdir($producerDir3);

        Loop::delay(
            200,
            function () use ($producerDir1, $producerDir2, $producerDir3) {
                touch($producerDir1 . DIRECTORY_SEPARATOR . 'job1');
                touch($producerDir2 . DIRECTORY_SEPARATOR . 'job2');
                touch($producerDir3 . DIRECTORY_SEPARATOR . 'job3');
            }
        );

        $this->stopWhen(
            function () {
                $successLog = array_filter(
                    $this->logHandler()->getRecords(),
                    function ($log) {
                        return strpos($log['message'], 'Successfully worked a Job') !== false;
                    }
                );
                return count($successLog) >= 3;
            },
            20
        );

        self::$kernel->boot();

        $this->assertReadyJobsCountInTube(0, self::FLOW1_CODE);
        $workerFileLines = $this->getFileLines($workerFile1);
        $this->assertCount(1, $workerFileLines);
        $worker1Line = $workerFileLines[0];
        $this->assertContains('job1', $worker1Line);
        $timestamp1 = $this->getLogLineTimestamp($worker1Line);

        $this->assertReadyJobsCountInTube(0, self::FLOW2_CODE);
        $workerFileLines = $this->getFileLines($workerFile2);
        $this->assertCount(1, $workerFileLines);
        $worker2Line = $workerFileLines[0];
        $this->assertContains('job2', $worker2Line);
        $timestamp2 = $this->getLogLineTimestamp($worker2Line);

        $this->assertReadyJobsCountInTube(0, self::FLOW3_CODE);
        $workerFileLines = $this->getFileLines($workerFile3);
        $this->assertCount(1, $workerFileLines);
        $worker3Line = $workerFileLines[0];
        $this->assertContains('job3', $worker3Line);
        $timestamp3 = $this->getLogLineTimestamp($worker3Line);

        //Checks that $timestamp1 >= $timestamp2
        $this->assertGreaterThanOrEqual(
            $timestamp2,
            $timestamp1,
            "Job 1 ({$timestamp1}) was worked before job 2 ({$timestamp2}), ".
            'but they should have been executed in the reverse order.'
        );

        //Checks that $timestamp2 >= $timestamp3
        $this->assertGreaterThanOrEqual(
            $timestamp3,
            $timestamp2,
            "Job 2 ({$timestamp2}) was worked before job 3 ({$timestamp3}), ".
            'but they should have been executed in the reverse order.'
        );
    }

    /**
     * @param string $worker1Line
     * @return float
     */
    private function getLogLineTimestamp(string $worker1Line): float
    {
        $matches = [];
        $this->assertTrue((bool)preg_match('/^(\d+) (\d+).*/', $worker1Line, $matches));
        return $matches[1] + (((float)$matches[2]) / 1000000);
    }
}
