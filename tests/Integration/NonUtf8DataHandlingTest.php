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

    private const FLOW_CODE = 'non_utf8_data_handling_flow';

    /**
     * @throws \Exception
     */
    public function testHandlesNonUtf8Data()
    {
        $producerDir = vfsStream::url('root/producer_dir');
        self::createKernel([
            'services' => [
                DummyFilesystemRepeatProducer::class => ['arguments' => [$producerDir]],
                DummyFilesystemWorker::class => ['arguments' => ['/dev/null']],
            ],
            'flows' => [
                self::FLOW_CODE => [
                    'description' => 'Non UTF8 Data Handling Test Flow',
                    'producer' => ['service' => DummyFilesystemRepeatProducer::class],
                    'worker' => ['service' => DummyFilesystemWorker::class],
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

        $this->assertStringNotContainsString('Successfully produced a new Job  []', $this->dumpLog());
        $this->assertStringNotContainsString('Successfully worked a Job  []', $this->dumpLog());
    }
}
