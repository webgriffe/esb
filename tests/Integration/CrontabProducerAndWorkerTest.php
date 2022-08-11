<?php

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use Amp\Promise;
use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\DateTimeBuilderInterface;
use Webgriffe\Esb\DateTimeBuilderStub;
use Webgriffe\Esb\DummyCrontabProducer;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\KernelTestCase;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\TestUtils;
use function Amp\File\exists;

class CrontabProducerAndWorkerTest extends KernelTestCase
{
    private const FLOW_CODE = 'crontab_flow';

    use TestUtils;

    public function testCrontabProducerAndWorkerDoesNotProduceIfIsNotTheRightTime()
    {
        vfsStream::setup();
        DummyCrontabProducer::$jobs = [new Job(['job1']), new Job(['job2'])];
        $workerFile = vfsStream::url('root/worker.data');
        self::createKernel([
            'services' => [
                DateTimeBuilderInterface::class => ['class' => DateTimeBuilderStub::class],
                DummyCrontabProducer::class => ['arguments' => []],
                DummyFilesystemWorker::class => ['arguments' => [$workerFile]],
            ],
            'flows' => [
                self::FLOW_CODE => [
                    'description' => 'Crontab Flow',
                    'producer' => ['service' => DummyCrontabProducer::class],
                    'worker' => ['service' => DummyFilesystemWorker::class],
                ]
            ]
        ]);

        DateTimeBuilderStub::$forcedNow = '2018-02-19 12:45:00';
        Loop::delay(200, function () {
            Loop::stop();
        });

        self::$kernel->boot();

        $this->assertFileDoesNotExist($workerFile);
    }

    public function testCrontabProducerAndWorkerProducesIfItsTheRightTime()
    {
        vfsStream::setup();
        DummyCrontabProducer::$jobs = [new Job(['job1']), new Job(['job2'])];
        $workerFile = vfsStream::url('root/worker.data');
        self::createKernel([
            'services' => [
                DateTimeBuilderInterface::class => ['class' => DateTimeBuilderStub::class],
                DummyCrontabProducer::class => ['arguments' => []],
                DummyFilesystemWorker::class => ['arguments' => [$workerFile]],
            ],
            'flows' => [
                self::FLOW_CODE => [
                    'description' => 'Crontab Flow',
                    'producer' => ['service' => DummyCrontabProducer::class],
                    'worker' => ['service' => DummyFilesystemWorker::class],
                ]
            ]
        ]);
        DateTimeBuilderStub::$forcedNow = '2018-02-19 13:00:00';
        $this->stopWhen(function () use ($workerFile) {
            return (yield exists($workerFile)) && count($this->getFileLines($workerFile)) === 2;
        });

        self::$kernel->boot();

        $workerFileLines = $this->getFileLines($workerFile);
        $this->assertStringContainsString('job1', $workerFileLines[0]);
        $this->assertStringContainsString('job2', $workerFileLines[1]);
        $this->assertReadyJobsCountInTube(0, self::FLOW_CODE);
    }
}
