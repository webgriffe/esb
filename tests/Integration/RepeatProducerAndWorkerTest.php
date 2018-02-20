<?php

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\DummyFilesystemRepeatProducer;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\KernelTestCase;

class RepeatProducerAndWorkerTest extends KernelTestCase
{
    public function testRepeatProducerAndWorkerTogether()
    {
        $producerDir = vfsStream::url('root/producer_dir');
        $workerFile = vfsStream::url('root/worker.data');
        self::createKernel([
            'services' => [
                DummyFilesystemRepeatProducer::class => ['arguments' => [$producerDir]],
                DummyFilesystemWorker::class => ['arguments' => [$workerFile]]
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
                        Loop::delay(200, function () {Loop::stop();});
                    }
                );
            }
        );

        self::$kernel->boot();

        $workerFileLines = $this->getFileLines($workerFile);
        $this->assertCount(2, $workerFileLines);
        $this->assertContains('job1', $workerFileLines[0]);
        $this->assertContains('job2', $workerFileLines[1]);
        $this->assertReadyJobsCountInTube(0, DummyFilesystemWorker::TUBE);
    }

    /**
     * @param $file
     * @return array
     */
    private function getFileLines($file): array
    {
        return array_filter(explode(PHP_EOL, file_get_contents($file)));
    }
}
