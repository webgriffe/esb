<?php

namespace Webgriffe\Esb;

use function Amp\call;
use Amp\File;
use Amp\Promise;
use Amp\Success;
use Webgriffe\Esb\Model\QueuedJob;

/**
 * This is a sample worker which simply writes job data to the /tmp/sample_worker.data file
 */
class DummyFilesystemWorker implements WorkerInterface
{
    const TUBE = 'sample_tube';

    /**
     * @var string
     */
    private $filename;

    public function __construct(string $filename)
    {
        $this->filename = $filename;
    }

    /**
     * @return string
     */
    public function getTube(): string
    {
        return self::TUBE;
    }

    /**
     * @return Promise
     * @throws \Error
     */
    public function init(): Promise
    {
        return new Success(null);
    }

    /**
     * @param QueuedJob $job
     * @return Promise
     */
    public function work(QueuedJob $job): Promise
    {
        return call(function () use ($job) {
            $content = '';
            if (yield File\exists($this->filename)) {
                $content = yield \Amp\File\get($this->filename);
            }
            $content .= date('c') . ' - ' . serialize($job->getPayloadData()) . PHP_EOL;
            yield File\put($this->filename, $content);
        });
    }

    /**
     * @return int
     */
    public function getReleaseDelay(): int
    {
        return 0;
    }

    /**
     * @return int
     */
    public function getInstancesCount(): int
    {
        return 1;
    }
}
