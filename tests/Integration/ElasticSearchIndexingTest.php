<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use Amp\Success;
use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\DummyFilesystemRepeatProducer;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\KernelTestCase;
use Webgriffe\Esb\Service\ElasticSearch;
use Webgriffe\Esb\TestUtils;
use Amp\Promise;
use function Amp\File\exists;

class ElasticSearchIndexingTest extends KernelTestCase
{
    const TUBE = 'sample_tube';

    use TestUtils;

    /**
     * @test
     */
    public function itIndexJobOntoElasticSearch()
    {
        $producerDir = vfsStream::url('root/producer_dir');
        $workerFile = vfsStream::url('root/worker.data');
        self::createKernel([
            'services' => [
                DummyFilesystemRepeatProducer::class => ['arguments' => [$producerDir]],
                DummyFilesystemWorker::class => ['arguments' => [$workerFile]],
            ],
            'flows' => [
                self::TUBE => [
                    'description' => 'Repeat Flow',
                    'producer' => ['service' => DummyFilesystemRepeatProducer::class],
                    'worker' => ['service' => DummyFilesystemWorker::class],
                ]
            ]
        ]);
        mkdir($producerDir);
        Loop::delay(
            200,
            function () use ($producerDir) {
                touch($producerDir . DIRECTORY_SEPARATOR . 'job1');
                Loop::delay(
                    200,
                    function () use ($producerDir) {
                        touch($producerDir . DIRECTORY_SEPARATOR . 'job2');
                    }
                );
            }
        );
        $this->stopWhen(function () use ($workerFile) {
            return (yield exists($workerFile)) && count($this->getFileLines($workerFile)) === 2;
        });
        self::$kernel->boot();

        $index = Promise\wait($this->esClient->statsIndex(ElasticSearch::INDEX_NAME, 'docs'));
        $this->assertEquals(2, $index['indices'][ElasticSearch::INDEX_NAME]['total']['docs']['count']);
    }
}
