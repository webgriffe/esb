<?php

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use Monolog\Logger;
use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\DummyFilesystemRepeatProducer;
use Webgriffe\Esb\DummyFlow;
use Webgriffe\Esb\DummyLongInitWorker;
use Webgriffe\Esb\KernelTestCase;

class LongInitWorkerTest extends KernelTestCase
{
    public function testLongInitWorker()
    {
        $producerDir = vfsStream::url('root/producer_dir');
        self::createKernel([
            'services' => [
                DummyFilesystemRepeatProducer::class => ['arguments' => [$producerDir]],
                DummyLongInitWorker::class => ['arguments' => ['@' . Logger::class]],
                DummyFlow::class => [
                    'arguments' => [
                        '@' . DummyFilesystemRepeatProducer::class,
                        '@' . DummyLongInitWorker::class,
                        'sample_tube'
                    ]
                ]
            ]
        ]);

        Loop::delay(500, function () {
            Loop::stop();
        });

        self::$kernel->boot();

        $logEntries = $this->logHandler()->getRecords();
        $this->assertCount(5, $logEntries);
        $this->assertContains('Starting async job in long init worker...', $logEntries[0]['formatted']);
        $this->assertContains('Web console server started.', $logEntries[1]['formatted']);
        $this->assertContains('A Producer has been successfully initialized', $logEntries[2]['formatted']);
        $this->assertContains('Async job done in long init worker, result is: done', $logEntries[3]['formatted']);
        $this->assertContains('A Worker has been successfully initialized', $logEntries[4]['formatted']);
        $this->assertContains('DummyLongInitWorker', $logEntries[4]['formatted']);
    }
}
