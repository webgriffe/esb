<?php

namespace Webgriffe\Esb\Integration;

use Monolog\Logger;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\DummyRepeatProducer;
use Webgriffe\Esb\MultiReasonInitFailingWorker;
use Webgriffe\Esb\KernelTestCase;

class UncaughtExceptionTest extends KernelTestCase
{
    public function testUncaughtExceptionIsLoggedAndThrown()
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
            self::$kernel->boot();
        } finally {
            $this->assertTrue($this->logHandler()->hasCriticalThatContains('An uncaught exception occurred, ESB will be stopped now!'));
        }
    }

    public function testMultiReasonExceptionIsLoggedAndThrown()
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
            self::$kernel->boot();
        } finally {
            $this->assertTrue($this->logHandler()->hasRecordThatPasses(function ($record) {
                $context = $record['context'];
                // Verify log message
                return $record['message'] === 'An uncaught exception occurred, ESB will be stopped now!'
                        // Verify log context contains all reasons
                        && isset($context['reasons']) && is_array($context['reasons'])
                        && count($context['reasons']) === 2
                        && $context['reasons'][0]['message'] === 'Exception number one'
                        && $context['reasons'][1]['message'] === 'Exception number two';
            }, Logger::CRITICAL));
        }
    }
}
