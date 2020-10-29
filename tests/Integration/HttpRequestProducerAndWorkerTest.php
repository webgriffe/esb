<?php

namespace Webgriffe\Esb\Integration;

use Amp\Artax\DefaultClient;
use Amp\Artax\Request;
use Amp\Artax\Response;
use Amp\Http\Status;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\ClientSocket;
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

    private $workerFile;
    private $httpPort;

    private const FLOW_CODE = 'http_producer_flow';

    public function setUp()
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
                    self::FLOW_CODE => [
                        'description' => 'Http Request Producer And Worker Test Flow',
                        'producer' => ['service' => DummyHttpRequestProducer::class],
                        'worker' => ['service' => DummyFilesystemWorker::class],
                    ]
                ]
            ]
        );
        $this->httpPort = self::$kernel->getContainer()->getParameter('http_server_port');
    }

    /**
     * @dataProvider dataProviderHttpRequestProducerAndWorker
     * @param string $payload
     * @param int $expectedResponseCode
     * @param string $expectedResponseMessage
     * @param string[] $expectedJobs
     * @param bool $expectError
     * @throws \Exception
     */
    public function testHttpRequestProducerAndWorker(
        string $payload,
        int $expectedResponseCode,
        string $expectedResponseMessage,
        array $expectedJobs,
        bool $expectError
    ) {
        Loop::delay(100, function () use ($payload, $expectedResponseCode, $expectedResponseMessage, $expectedJobs) {
            yield $this->waitForConnectionAvailable("tcp://127.0.0.1:{$this->httpPort}");
            $client = new DefaultClient();
            $request = (new Request("http://127.0.0.1:{$this->httpPort}/dummy", 'POST'))->withBody($payload);
            /** @var Response $response */
            $response = yield $client->request($request);
            $this->assertSame($expectedResponseCode, $response->getStatus());
            $this->assertSame($expectedResponseMessage, yield $response->getBody());

            if (empty($expectedJobs)) {
                Loop::delay(200, function () {
                    Loop::stop();
                });
            } else {
                $this->stopWhen(function () use ($expectedJobs) {
                    return (yield exists($this->workerFile)) && count($this->getFileLines($this->workerFile)) >= count($expectedJobs);
                });
            }
        });

        self::$kernel->boot();

        $this->assertSame($expectError, $this->logHandler()->hasRecordThatContains(
            'An error occurred producing/queueing jobs.',
            Logger::ERROR
        ));
        $this->assertWorkedJobs($expectedJobs);
    }

    /**
     * Data provider for testHttpRequestProducerAndWorker
     * @return array[]
     */
    public function dataProviderHttpRequestProducerAndWorker(): array
    {
        return [
            'successfully scheduled' => [
                'payload' => json_encode(['jobs' => ['job1', 'job2', 'job3']]),
                'expectedResponseCode' => Status::OK,
                'expectedResponseMessage' => '"Successfully scheduled 3 job(s) to be queued."',
                'expectedJobs' => ['job1', 'job2', 'job3'],
                'expectError' => false,
            ],
            'complete fail' => [
                'payload' => json_encode('not an array'),
                'expectedResponseCode' => Status::BAD_REQUEST,
                'expectedResponseMessage' => '"Request body contains invalid JSON, could not schedule any jobs."',
                'expectedJobs' => [],
                'expectError' => true,
            ],
            'other custom message' => [
                'payload' => json_encode(['jobs' => ['throw http response exception']]),
                'expectedResponseCode' => Status::PRECONDITION_FAILED,
                'expectedResponseMessage' => '"Some other custom message, could not schedule any jobs."',
                'expectedJobs' => [],
                'expectError' => true,
            ],
            'default message' => [
                'payload' => json_encode(['jobs' => ['throw other exception']]),
                'expectedResponseCode' => Status::INTERNAL_SERVER_ERROR,
                'expectedResponseMessage' => '"Internal server error, could not schedule any jobs."',
                'expectedJobs' => [],
                'expectError' => true,
            ],
            'first two jobs scheduled, third failed, fourth never scheduled' => [
                'payload' => json_encode(['jobs' => ['job1', 'job2', 'throw http response exception', 'job4']]),
                'expectedResponseCode' => Status::PRECONDITION_FAILED,
                'expectedResponseMessage' => '"Some other custom message, only scheduled the first 2 job(s) to be queued."',
                'expectedJobs' => ['job1', 'job2'],
                'expectError' => true,
            ],
        ];
    }

    public function testHttpRequestProducerWithWrongUriShouldReturn404()
    {
        Loop::delay(100, function () {
            yield $this->waitForConnectionAvailable("tcp://127.0.0.1:{$this->httpPort}");
            $payload = json_encode(['jobs' => ['job1', 'job2', 'job3']]);
            $client = new DefaultClient();
            $request = (new Request("http://127.0.0.1:{$this->httpPort}/wrong-uri", 'POST'))->withBody($payload);
            /** @var Response $response */
            $response = yield $client->request($request);
            $this->assertEquals(404, $response->getStatus());
            Loop::delay(200, function () {
                Loop::stop();
            });
        });

        self::$kernel->boot();

        $this->assertWorkedJobs([]);
    }

    private function waitForConnectionAvailable(string $uri): Promise
    {
        return call(function () use ($uri) {
            do {
                try {
                    /** @var ClientSocket $connection */
                    $connection = yield connect($uri);
                } catch (ConnectException $e) {
                    $connection = null;
                }
            } while ($connection === null);
            $connection->close();
        });
    }

    /**
     * @param string[] $jobs
     */
    private function assertWorkedJobs(array $jobs): void
    {
        if (count($jobs) === 0) {
            $this->assertFileNotExists($this->workerFile);
        } else {
            $workerFileLines = $this->getFileLines($this->workerFile);
            $this->assertCount(count($jobs), $workerFileLines);
            foreach ($jobs as $index => $jobData) {
                $this->assertContains($jobData, $workerFileLines[$index]);

                $this->assertTrue($this->logHandler()->hasRecordThatPasses(
                    function (array $logEntry) use ($jobData): bool {
                        return $logEntry['message'] === 'Successfully produced a new Job'
                            && $logEntry['context']['payload_data'] === [$jobData];
                    },
                    Logger::INFO
                ));
            }
        }

        $this->assertReadyJobsCountInTube(0, self::FLOW_CODE);
    }
}
