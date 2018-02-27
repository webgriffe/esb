<?php

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\DateTimeBuilderInterface;
use Webgriffe\Esb\DateTimeBuilderStub;
use Webgriffe\Esb\DummyCrontabProducer;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\KernelTestCase;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\TestUtils;

class CrontabProducerAndWorkerTest extends KernelTestCase
{
    use TestUtils;

    public function testCrontabProducerAndWorkerDoesNotProduceIfIsNotTheRightTime()
    {
        vfsStream::setup();
        $workerFile = vfsStream::url('root/worker.data');
        self::createKernel([
            'services' => [
                DateTimeBuilderInterface::class => ['class' => DateTimeBuilderStub::class],
                DummyCrontabProducer::class => ['arguments' => [DummyFilesystemWorker::TUBE]],
                DummyFilesystemWorker::class => ['arguments' => [$workerFile]]
            ]
        ]);

        $jobs = [new Job(['job1']), new Job(['job2'])];
        /** @var DummyCrontabProducer $producer */
        $producer = self::$kernel->getContainer()->get(DummyCrontabProducer::class);
        $producer->setJobs($jobs);

        DateTimeBuilderStub::$forcedNow = '2018-02-19 12:45:00';
        Loop::delay(200, function () {Loop::stop();});

        self::$kernel->boot();

        $this->assertFileNotExists($workerFile);
    }

    public function testCrontabProducerAndWorkerProducesIfItsTheRightTime()
    {
        vfsStream::setup();
        $workerFile = vfsStream::url('root/worker.data');
        self::createKernel([
            'services' => [
                DateTimeBuilderInterface::class => ['class' => DateTimeBuilderStub::class],
                DummyCrontabProducer::class => ['arguments' => [DummyFilesystemWorker::TUBE]],
                DummyFilesystemWorker::class => ['arguments' => [$workerFile]]
            ]
        ]);

        $jobs = [new Job(['job1']), new Job(['job2'])];
        /** @var DummyCrontabProducer $producer */
        $producer = self::$kernel->getContainer()->get(DummyCrontabProducer::class);
        $producer->setJobs($jobs);

        DateTimeBuilderStub::$forcedNow = '2018-02-19 13:00:00';
        Loop::delay(200, function () {Loop::stop();});

        self::$kernel->boot();

        $this->assertFileExists($workerFile);
        $workerFileLines = $this->getFileLines($workerFile);
        $this->assertCount(2, $workerFileLines);
        $this->assertContains('job1', $workerFileLines[0]);
        $this->assertContains('job2', $workerFileLines[1]);
        $this->assertReadyJobsCountInTube(0, DummyFilesystemWorker::TUBE);
    }
}
