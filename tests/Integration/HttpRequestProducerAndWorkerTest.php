<?php

namespace Webgriffe\Esb\Integration;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\ClientSocket;
use Amp\Socket\ConnectException;
use Amp\Socket\Socket;
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

    private $workerFile;
    private $httpPort;

    const TUBE = 'sample_tube';

    public function setUp(): void
    {
        parent::setUp();
        $this->workerFile = vfsStream::url('root/worker.data');
        self::createKernel(
            [
                'services' => [
                    DummyHttpRequestProducer::class => ['arguments' => []],
                    DummyFilesystemWorker::class => ['arguments' => [$this->workerFile]],
                ],
                'flows' => [
                    self::TUBE => [
                        'description' => 'Http Flow',
                        'producer' => ['service' => DummyHttpRequestProducer::class],
                        'worker' => ['service' => DummyFilesystemWorker::class],
                    ]
                ]
            ]
        );
        $this->httpPort = self::$kernel->getContainer()->getParameter('http_server_port');
    }

    public function testHttpRequestProducerAndWorker()
    {
        Loop::delay(100, function () {
            yield $this->waitForConnectionAvailable("tcp://127.0.0.1:{$this->httpPort}");
            $payload = json_encode(['jobs' => ['job1', 'job2', 'job3']]);
            $client = HttpClientBuilder::buildDefault();
            $request = new Request("http://127.0.0.1:{$this->httpPort}/dummy", 'POST');
            $request->setBody($payload);
            /** @var Response $response */
            $response = yield $client->request($request);
            $this->assertStringContainsString(
                '"Successfully scheduled 3 job(s) to be queued."',
                yield $response->getBody()->read()
            );
        });
        $this->stopWhen(function () {
            return (yield exists($this->workerFile)) && count($this->getFileLines($this->workerFile)) === 3;
        });

        self::$kernel->boot();

        $workerFileLines = $this->getFileLines($this->workerFile);
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
        $this->assertReadyJobsCountInTube(0, self::TUBE);
    }

    public function testHttpRequestProducerWithWrongUriShouldReturn404()
    {
        Loop::delay(100, function () {
            yield $this->waitForConnectionAvailable("tcp://127.0.0.1:{$this->httpPort}");
            $payload = json_encode(['jobs' => ['job1', 'job2', 'job3']]);
            $client = HttpClientBuilder::buildDefault();
            $request = new Request("http://127.0.0.1:{$this->httpPort}/wrong-uri", 'POST');
            $request->setBody($payload);
            /** @var Response $response */
            $response = yield $client->request($request);
            $this->assertEquals(404, $response->getStatus());
            Loop::delay(200, function () {
                Loop::stop();
            });
        });

        self::$kernel->boot();

        $this->assertFileDoesNotExist($this->workerFile);
        $this->assertReadyJobsCountInTube(0, self::TUBE);
    }

    private function waitForConnectionAvailable(string $uri): Promise
    {
        return call(function () use ($uri) {
            do {
                try {
                    /** @var Socket $connection */
                    $connection = yield connect($uri);
                } catch (ConnectException $e) {
                    $connection = null;
                }
            } while ($connection === null);
            $connection->close();
        });
    }
}
