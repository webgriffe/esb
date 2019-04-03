<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Integration;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Loop;
use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\BeanstalkTestCase;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\DummyRepeatProducer;
use Webgriffe\Esb\KernelTestCase;

class JobUnserializationErrorHandlingTest extends KernelTestCase
{
    const TUBE = 'sample_tube';

    public function testNotUnserializableJobShouldBeHandled()
    {
        $workerFile = vfsStream::url('root/worker.data');
        self::createKernel([
            'services' => [
                DummyRepeatProducer::class => ['arguments' => []],
                DummyFilesystemWorker::class => ['arguments' => [$workerFile]],
            ],
            'flows' => [
                self::TUBE => [
                    'description' => 'Repeat Flow',
                    'producer' => ['service' => DummyRepeatProducer::class],
                    'worker' => [
                        'service' => DummyFilesystemWorker::class,
                        'release_delay' => 1,
                        'max_retry' => 2
                    ],
                ]
            ]
        ]);

        Loop::delay(
            200,
            function () {
                $beanstalkdClient = new BeanstalkClient(
                    BeanstalkTestCase::getBeanstalkdConnectionUri() . '?tube=' . self::TUBE
                );
                yield $beanstalkdClient->put('... not unserializable payload ...');
                Loop::delay(
                    200,
                    function () {
                        Loop::stop();
                    }
                );
            }
        );

        self::$kernel->boot();

        $this->assertContains('Cannot unserialize job payload so it has been buried.', $this->dumpLog());
        $this->assertReadyJobsCountInTube(0, self::TUBE);
        $this->assertBuriedJobsCountInTube(1, self::TUBE);
    }
}
