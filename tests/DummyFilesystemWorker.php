<?php

namespace Webgriffe\Esb;

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
     */
    public function work(QueuedJob $job)
    {
        file_put_contents(
            $this->filename,
            date('c') . ' - ' . serialize($job->getPayloadData()) . PHP_EOL,
            FILE_APPEND
        );
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
