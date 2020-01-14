<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\DummyFilesystemRepeatProducer;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\KernelTestCase;
use Webgriffe\Esb\TestUtils;
use function Amp\File\exists;

class TwoFlowsTest extends KernelTestCase
{
    private const FLOW1_CODE = 'two_flows_flow1';
    private const FLOW2_CODE = 'two_flows_flow2';

    use TestUtils;

    public function testTwoFlows()
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
        mkdir($producerDir1);
        mkdir($producerDir2);
        Loop::delay(
            200,
            function () use ($producerDir1, $producerDir2) {
                touch($producerDir1 . DIRECTORY_SEPARATOR . 'job1');
                touch($producerDir2 . DIRECTORY_SEPARATOR . 'job1');
                Loop::delay(
                    200,
                    function () use ($producerDir1, $producerDir2) {
                        touch($producerDir1 . DIRECTORY_SEPARATOR . 'job2');
                        touch($producerDir2 . DIRECTORY_SEPARATOR . 'job2');
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
            return count($successLog) >= 4;
        });

        self::$kernel->boot();

        $workerFileLines = $this->getFileLines($workerFile1);
        $this->assertOneArrayEntryContains('job1', $workerFileLines);
        $this->assertOneArrayEntryContains('job2', $workerFileLines);
        $this->assertReadyJobsCountInTube(0, self::FLOW1_CODE);
        $workerFileLines = $this->getFileLines($workerFile2);
        $this->assertOneArrayEntryContains('job1', $workerFileLines);
        $this->assertOneArrayEntryContains('job2', $workerFileLines);
        $this->assertReadyJobsCountInTube(0, self::FLOW1_CODE);
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
