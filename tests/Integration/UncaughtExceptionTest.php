<?php

namespace Webgriffe\Esb\Integration;

use Monolog\Level;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\DummyRepeatProducer;
use Webgriffe\Esb\MultiReasonInitFailingWorker;
use Webgriffe\Esb\KernelTestCase;
use Monolog\LogRecord;

class UncaughtExceptionTest extends KernelTestCase
{
    public function testUncaughtExceptionIsLoggedAndThrown(): void
    {
        self::createKernel([
            'parameters' => ['beanstalkd' => 'tcp://invalid-host:11300'],
            'services' => [
                DummyRepeatProducer::class => ['class' => DummyRepeatProducer::class, 'arguments' => []],
                DummyFilesystemWorker::class => ['arguments' => ['/dev/null']],
            ],
            'flows' => [
                'sample_tube' => [
                    'description' => 'Flow',
                    'producer' => ['service' => DummyRepeatProducer::class],
                    'worker' => ['service' => DummyFilesystemWorker::class],
                ]
            ]
        ]);

        try {
            $this->expectException(\Amp\Beanstalk\ConnectException::class);
            self::kernel()->boot();
        } finally {
            $this->assertTrue($this->logHandler()->hasCriticalThatContains('An uncaught exception occurred, ESB will be stopped now!'));
        }
    }

    public function testMultiReasonExceptionIsLoggedAndThrown(): void
    {
        self::createKernel([
            'services' => [
                DummyRepeatProducer::class => ['class' => DummyRepeatProducer::class, 'arguments' => []],
                MultiReasonInitFailingWorker::class => [],
            ],
            'flows' => [
                'sample_tube' => [
                    'description' => 'Flow',
                    'producer' => ['service' => DummyRepeatProducer::class],
                    'worker' => ['service' => MultiReasonInitFailingWorker::class],
                ]
            ]
        ]);

        try {
            $this->expectException(\Amp\MultiReasonException::class);
            self::kernel()->boot();
        } finally {
            $this->assertTrue($this->logHandler()->hasRecordThatPasses(function (LogRecord $record) {
                $reasons = $record->context['reasons'] ?? null;
                // Verify log message
                return $record->message === 'An uncaught exception occurred, ESB will be stopped now!'
                        // Verify log context contains all reasons
                        && is_array($reasons)
                        && count($reasons) === 2
                        && is_array($reasons[0]) && ($reasons[0]['message'] ?? null) === 'Exception number one'
                        && is_array($reasons[1]) && ($reasons[1]['message'] ?? null) === 'Exception number two';
            }, Level::Critical));
        }
    }
}
