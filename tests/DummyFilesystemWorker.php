<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\File;
use Amp\Promise;
use Amp\Success;
use Webgriffe\Esb\Model\JobInterface;
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
     * {@inheritDoc}
     */
    public function work(JobInterface $job): Promise
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
}
