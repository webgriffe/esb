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
    const TUBE1 = 'flow1';
    const TUBE2 = 'flow2';

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
                self::TUBE1 => [
                    'description' => 'Flow 1',
                    'producer' => ['service' => 'producer1'],
                    'worker' => ['service' => 'worker1'],
                ],
                self::TUBE2 => [
                    'description' => 'Flow 2',
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
        $this->stopWhen(function () use ($workerFile1, $workerFile2) {
            return ((yield exists($workerFile1)) && count($this->getFileLines($workerFile1)) === 2) &&
                    ((yield exists($workerFile2)) && count($this->getFileLines($workerFile2)) === 2);
        });

        self::$kernel->boot();

        $workerFileLines = $this->getFileLines($workerFile1);
        $this->assertStringContainsString('job1', $workerFileLines[0]);
        $this->assertStringContainsString('job2', $workerFileLines[1]);
        $this->assertReadyJobsCountInTube(0, self::TUBE1);
        $workerFileLines = $this->getFileLines($workerFile2);
        $this->assertStringContainsString('job1', $workerFileLines[0]);
        $this->assertStringContainsString('job2', $workerFileLines[1]);
        $this->assertReadyJobsCountInTube(0, self::TUBE1);
    }
}
