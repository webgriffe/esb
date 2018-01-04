<?php

namespace Webgriffe\Esb\Integration;

use Amp\Artax\DefaultClient;
use Amp\Artax\Request;
use Amp\Loop;
use Monolog\Logger;
use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\DummyHttpServerProducer;
use Webgriffe\Esb\KernelTestCase;

class HttpServerProducerAndWorkerTest extends KernelTestCase
{
    public function testHttpServerProducerAndWorker()
    {
        $workerFile = vfsStream::url('root/worker.data');
        self::createKernel([
            'services' => [
                DummyHttpServerProducer::class => ['arguments' => []],
                DummyFilesystemWorker::class => ['arguments' => [$workerFile]]
            ]
        ]);
        Loop::delay(10, function () {
            $payload = json_encode(['jobs' => ['job1', 'job2', 'job3']]);
            $client = new DefaultClient();
            $request = (new Request('http://127.0.0.1:8080/dummy', 'POST'))->withBody($payload);
            $response = yield $client->request($request);
            $this->assertContains('"Successfully scheduled 3 job(s) to be queued."', yield $response->getBody());
            Loop::delay(10, function () {Loop::stop();});
        });

        self::$kernel->boot();

        $workerFileLines = $this->getFileLines($workerFile);
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

    /**
     * @param $file
     * @return array
     */
    private function getFileLines($file): array
    {
        return array_filter(explode(PHP_EOL, file_get_contents($file)));
    }
}
