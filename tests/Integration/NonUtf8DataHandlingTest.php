<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\DummyFilesystemRepeatProducer;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\KernelTestCase;
use Webgriffe\Esb\TestUtils;

class NonUtf8DataHandlingTest extends KernelTestCase
{
    use TestUtils;

    /**
     * @throws \Exception
     */
    public function testRepeatProducerAndWorkerTogether()
    {
        $producerDir = vfsStream::url('root/producer_dir');
        self::createKernel([
            'services' => [
                DummyFilesystemRepeatProducer::class => ['arguments' => [$producerDir]],
                DummyFilesystemWorker::class => ['arguments' => ['/dev/null']],
            ],
            'flows' => [
                [
                    'name' => 'DummyFlow',
                    'tube' => 'sample_tube',
                    'producer' => DummyFilesystemRepeatProducer::class,
                    'worker' => DummyFilesystemWorker::class,
                    'workerInstances' => 1
                ]
            ]
        ]);

        mkdir($producerDir);
        Loop::delay(
            200,
            function () use ($producerDir) {
                copy(__DIR__ . '/non-utf8-data-file.csv', $producerDir . DIRECTORY_SEPARATOR . 'job1');
                Loop::delay(
                    200,
                    function () {
                        Loop::stop();
                    }
                );
            }
        );

        self::$kernel->boot();

        $this->assertNotContains('Successfully produced a new Job  []', $this->dumpLog());
        $this->assertNotContains('Successfully worked a Job  []', $this->dumpLog());
    }
}
