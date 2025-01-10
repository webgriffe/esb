<?php

namespace Webgriffe\Esb\Integration;

use Amp\Beanstalk\ConnectException;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\DummyRepeatProducer;
use Webgriffe\Esb\KernelTestCase;

class UncaughtExceptionTest extends KernelTestCase
{
    public function testUncaughtExceptionIsLoggedAndThrown()
    {
        try {
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
            self::$kernel->boot();

            $this->fail('Expected exception was not thrown');
        } catch (ConnectException $e) {
            //All as expected
        } catch (\Throwable $e) {
            throw $e;
        }

        $this->logHandler()->hasCriticalThatContains('An uncaught exception occurred, ESB will be stopped now!');
    }
}
