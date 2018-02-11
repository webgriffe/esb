<?php

namespace Webgriffe\Esb\Integration;

use Amp\Artax\DefaultClient;
use Amp\Artax\Request;
use Amp\Artax\Response;
use Amp\Loop;
use Monolog\Logger;
use org\bovigo\vfs\vfsStream;
use Psr\Http\Message\ResponseInterface;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\DummyHttpRequestProducer;
use Webgriffe\Esb\KernelTestCase;

class HttpRequestProducerAndWorkerTest extends KernelTestCase
{
    private $workerFile;
    private $httpPort;

    public function setUp()
    {
        parent::setUp();
        $this->workerFile = vfsStream::url('root/worker.data');
        $this->httpPort = self::getHttpServerPort();
        self::createKernel(
            [
                'services' => [
                    DummyHttpRequestProducer::class => ['arguments' => []],
                    DummyFilesystemWorker::class => ['arguments' => [$this->workerFile]]
                ]
            ]
        );
    }

    public function testHttpRequestProducerAndWorker()
    {
        Loop::delay(100, function () {
            $payload = json_encode(['jobs' => ['job1', 'job2', 'job3']]);
            $client = new DefaultClient();
            $request = (new Request("http://127.0.0.1:{$this->httpPort}/dummy", 'POST'))->withBody($payload);
            $response = yield $client->request($request);
            $this->assertContains('"Successfully scheduled 3 job(s) to be queued."', yield $response->getBody());
            Loop::delay(100, function () {Loop::stop();});
        });

        self::$kernel->boot();

        $workerFileLines = $this->getFileLines($this->workerFile);
        $this->assertCount(3, $workerFileLines);
        $this->assertContains('job1', $workerFileLines[0]);
        $this->assertContains('job2', $workerFileLines[1]);
        $this->assertContains('job3', $workerFileLines[2]);
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
        $this->assertReadyJobsCountInTube(0, DummyFilesystemWorker::TUBE);
    }

    public function testHttpRequestProducerWithWrongUriShouldReturn404()
    {
        Loop::delay(100, function () {
            $payload = json_encode(['jobs' => ['job1', 'job2', 'job3']]);
            $client = new DefaultClient();
            $request = (new Request("http://127.0.0.1:{$this->httpPort}/wrong-uri", 'POST'))->withBody($payload);
            /** @var Response $response */
            $response = yield $client->request($request);
            $this->assertEquals(404, $response->getStatus());
            Loop::delay(100, function () {Loop::stop();});
        });

        self::$kernel->boot();

        $this->assertFileNotExists($this->workerFile);
        $this->assertReadyJobsCountInTube(0, DummyFilesystemWorker::TUBE);
    }

    /**
     * @param $file
     * @return array
     */
    private function getFileLines($file): array
    {
        return array_filter(explode(PHP_EOL, file_get_contents($file)));
    }
}
