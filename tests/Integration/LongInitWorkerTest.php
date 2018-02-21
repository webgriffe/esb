<?php

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use Monolog\Logger;
use Webgriffe\Esb\DummyLongInitWorker;
use Webgriffe\Esb\KernelTestCase;

class LongInitWorkerTest extends KernelTestCase
{
    public function testLongInitWorker()
    {
        self::createKernel([
            'services' => [
                DummyLongInitWorker::class => ['arguments' => ['@' . Logger::class]]
            ]
        ]);

        Loop::delay(500, function () {Loop::stop();});

        self::$kernel->boot();

        $logEntries = $this->logHandler()->getRecords();
        $this->assertCount(4, $logEntries);
        $this->assertContains('No producer to start', $logEntries[0]['formatted']);
        $this->assertContains('Starting async job in long init worker...', $logEntries[1]['formatted']);
        $this->assertContains('Async job done in long init worker, result is: done', $logEntries[2]['formatted']);
        $this->assertContains('A Worker has been successfully initialized', $logEntries[3]['formatted']);
        $this->assertContains('DummyLongInitWorker', $logEntries[3]['formatted']);
    }
}
