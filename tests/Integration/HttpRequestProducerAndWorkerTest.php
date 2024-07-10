<?php

namespace Webgriffe\Esb\Integration;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\ConnectException;
use Monolog\Logger;
use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\DummyHttpRequestProducer;
use Webgriffe\Esb\KernelTestCase;
use Webgriffe\Esb\TestUtils;

use function Amp\call;
use function Amp\File\exists;
use function Amp\Socket\connect;

class HttpRequestProducerAndWorkerTest extends KernelTestCase
{
    use TestUtils;

    private const FLOW_CODE = 'http_producer_flow';

    public function testHttpRequestProducerAndWorker()
    {
        $workerFile = vfsStream::url('root/worker.data');
        self::createKernel(
            [
                'services' => [
                    DummyHttpRequestProducer::class => ['arguments' => []],
                    DummyFilesystemWorker::class => ['arguments' => [$workerFile]],
                ],
                'flows' => [
                    self::FLOW_CODE => [
                        'description' => 'Http Request Producer And Worker Test Flow',
                        'producer' => ['service' => DummyHttpRequestProducer::class],
                        'worker' => ['service' => DummyFilesystemWorker::class],
                    ]
                ]
            ]
        );
        $httpPort = self::$kernel->getContainer()->getParameter('http_server_port');

        Loop::delay(100, function () use ($httpPort){
            yield $this->waitForConnectionAvailable("tcp://127.0.0.1:{$httpPort}");
            $payload = json_encode(['jobs' => ['job1', 'job2', 'job3']]);
            $client = HttpClientBuilder::buildDefault();
            $request = new Request("http://127.0.0.1:{$httpPort}/dummy", 'POST');
            $request->setBody($payload);
            $response = yield $client->request($request);
            $this->assertStringContainsString(
                '"Successfully scheduled 3 job(s) to be queued."',
                yield $response->getBody()->read()
            );
        });
        $this->stopWhen(function () use ($workerFile) {
            return (yield exists($workerFile)) && count($this->getFileLines($workerFile)) === 3;
        });

        self::$kernel->boot();

        $workerFileLines = $this->getFileLines($workerFile);
        $this->assertCount(3, $workerFileLines);
        $this->assertStringContainsString('job1', $workerFileLines[0]);
        $this->assertStringContainsString('job2', $workerFileLines[1]);
        $this->assertStringContainsString('job3', $workerFileLines[2]);
        $this->logHandler()->hasRecordThatMatches(
            '/Successfully produced a new Job .*? "payload_data":["job1"]/',
            Logger::INFO
        );
        $this->logHandler()->hasRecordThatMatches(
            '/Successfully produced a new Job .*? "payload_data":["job2"]/',
            Logger::INFO
        );
        $this->logHandler()->hasRecordThatMatches(
            '/Successfully produced a new Job .*? "payload_data":["job3"]/',
            Logger::INFO
        );
        $this->assertReadyJobsCountInTube(0, self::FLOW_CODE);
    }

    public function testMultipleHttpRequestProducersForSameRequest()
    {
        $firstWorkerFile = vfsStream::url('root/first-worker.data');
        $secondWorkerFile = vfsStream::url('root/second-worker.data');

        self::createKernel(
            [
                'services' => [
                    'http_request_producer' => [
                        'class' => DummyHttpRequestProducer::class,
                        'arguments' => []
                    ],
                    'first_filesystem_worker' => [
                        'class' => DummyFilesystemWorker::class,
                        'arguments' => [$firstWorkerFile]
                    ],
                    'second_filesystem_worker' => [
                        'class' => DummyFilesystemWorker::class,
                        'arguments' => [$secondWorkerFile]
                    ],
                ],
                'flows' => [
                    'first_http_producer_flow' => [
                        'description' => 'First Http Request Producer And Worker Test Flow',
                        'producer' => ['service' => 'http_request_producer'],
                        'worker' => ['service' => 'first_filesystem_worker'],
                    ],
                    'second_http_producer_flow' => [
                        'description' => 'Second Http Request Producer And Worker Test Flow',
                        'producer' => ['service' => 'http_request_producer'],
                        'worker' => ['service' => 'second_filesystem_worker'],
                    ]
                ]
            ]
        );
        $httpPort = self::$kernel->getContainer()->getParameter('http_server_port');

        Loop::delay(100, function () use ($httpPort){
            yield $this->waitForConnectionAvailable("tcp://127.0.0.1:{$httpPort}");
            $payload = json_encode(['jobs' => ['job1']]);
            $client = HttpClientBuilder::buildDefault();
            $request = new Request("http://127.0.0.1:{$httpPort}/dummy", 'POST');
            $request->setBody($payload);
            $response = yield $client->request($request);
            $this->assertStringContainsString(
                '"Successfully scheduled 2 job(s) to be queued."',
                yield $response->getBody()->read()
            );
        });
        $this->stopWhen(function () use ($firstWorkerFile, $secondWorkerFile) {
            return
                (yield exists($firstWorkerFile)) &&
                count($this->getFileLines($firstWorkerFile)) === 1 &&
                (yield exists($secondWorkerFile)) &&
                count($this->getFileLines($secondWorkerFile)) === 1
            ;
        });

        self::$kernel->boot();

        $firstWorkerFileLines = $this->getFileLines($firstWorkerFile);
        $secondWorkerFileLines = $this->getFileLines($secondWorkerFile);
        $this->assertCount(1, $firstWorkerFileLines);
        $this->assertCount(1, $secondWorkerFileLines);
        $this->assertStringContainsString('job1', $firstWorkerFileLines[0]);
        $this->assertStringContainsString('job1', $secondWorkerFileLines[0]);
        $this->assertReadyJobsCountInTube(0, 'first_http_producer_flow');
        $this->assertReadyJobsCountInTube(0, 'second_http_producer_flow');
    }

    public function testHttpRequestProducerWithWrongUriShouldReturn404()
    {
        $workerFile = vfsStream::url('root/worker.data');
        self::createKernel(
            [
                'services' => [
                    DummyHttpRequestProducer::class => ['arguments' => []],
                    DummyFilesystemWorker::class => ['arguments' => [$workerFile]],
                ],
                'flows' => [
                    self::FLOW_CODE => [
                        'description' => 'Http Request Producer And Worker Test Flow',
                        'producer' => ['service' => DummyHttpRequestProducer::class],
                        'worker' => ['service' => DummyFilesystemWorker::class],
                    ]
                ]
            ]
        );
        $httpPort = self::$kernel->getContainer()->getParameter('http_server_port');
        
        Loop::delay(100, function () use ($httpPort) {
            yield $this->waitForConnectionAvailable("tcp://127.0.0.1:{$httpPort}");
            $payload = json_encode(['jobs' => ['job1', 'job2', 'job3']]);
            $client = HttpClientBuilder::buildDefault();
            $request = new Request("http://127.0.0.1:{$httpPort}/wrong-uri", 'POST');
            $request->setBody($payload);
            $response = yield $client->request($request);
            $this->assertEquals(404, $response->getStatus());
            Loop::delay(200, function () {
                Loop::stop();
            });
        });

        self::$kernel->boot();

        $this->assertFileDoesNotExist($workerFile);
        $this->assertReadyJobsCountInTube(0, self::FLOW_CODE);
    }

    private function waitForConnectionAvailable(string $uri): Promise
    {
        return call(function () use ($uri) {
            do {
                try {
                    $connection = yield connect($uri);
                } catch (ConnectException $e) {
                    $connection = null;
                }
            } while ($connection === null);
            $connection->close();
        });
    }
}
