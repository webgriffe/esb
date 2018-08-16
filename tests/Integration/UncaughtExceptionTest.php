<?php

namespace Webgriffe\Esb\Integration;

use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\DummyFlow;
use Webgriffe\Esb\DummyRepeatProducer;
use Webgriffe\Esb\KernelTestCase;

class UncaughtExceptionTest extends KernelTestCase
{
    /**
     * @expectedException \Amp\Beanstalk\ConnectException
     */
    public function testUncaughtExceptionIsLoggedAndThrown()
    {
        self::createKernel([
            'parameters' => ['beanstalkd' => 'tcp://invalid-host:11300'],
            'services' => [
                DummyRepeatProducer::class => ['class' => DummyRepeatProducer::class, 'arguments' => [[], 1]],
                DummyFilesystemWorker::class => ['arguments' => ['/dev/null']],
                DummyFlow::class => [
                    'arguments' => [
                        '@' . DummyRepeatProducer::class,
                        '@' . DummyFilesystemWorker::class,
                        'sample_tube'
                    ]
                ]
            ]
        ]);
        self::$kernel->boot();

        $this->logHandler()->hasCriticalThatContains('An uncaught exception occurred, ESB will be stopped now!');
    }
}
