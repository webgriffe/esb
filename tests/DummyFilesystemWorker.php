<?php

namespace Webgriffe\Esb;

use Amp\Promise;
use Amp\File;
use Amp\Success;
use Webgriffe\Esb\Model\QueuedJob;
use function Amp\call;

/**
 * This is a sample worker which simply writes job data to the /tmp/sample_worker.data file
 */
final class DummyFilesystemWorker implements WorkerInterface
{
    /**
     * @var string
     */
    private $filename;

    public function __construct(string $filename)
    {
        $this->filename = $filename;
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
            //The date() function does not support microseconds, whereas DateTime does.
            $now = new \DateTime('now');
            $content .= $now->format('U u') . ' - ' . serialize($job->getPayloadData()) . PHP_EOL;
            yield File\put($this->filename, $content);
        });
    }
}
