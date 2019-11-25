<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\AlwaysFailingProducer;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\KernelTestCase;
use Webgriffe\Esb\TestUtils;

class FailingProducerTest extends KernelTestCase
{
    use TestUtils;

    private const FLOW_CODE = 'failing_producer_flow';

    public function testFailingProducerShouldBeCaughtAndLogged()
    {
        $workerFile = vfsStream::url('root/worker.data');
        self::createKernel([
            'services' => [
                AlwaysFailingProducer::class => ['arguments' => [10]],
                DummyFilesystemWorker::class => ['arguments' => [$workerFile]],
            ],
            'flows' => [
                self::FLOW_CODE => [
                    'description' => 'Failing Producer Flow',
                    'producer' => ['service' => AlwaysFailingProducer::class],
                    'worker' => ['service' => DummyFilesystemWorker::class],
                ]
            ]
        ]);
        $this->stopWhen(function () {
            return $this->logHandler()->hasErrorRecords();
        });

        self::$kernel->boot();

        $this->assertTrue($this->logHandler()->hasErrorThatContains('An error occurred producing/queueing jobs'));
    }
}
