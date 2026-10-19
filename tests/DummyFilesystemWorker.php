<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

use function Amp\delay;
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
    public function __construct(
        private readonly string $filename,
        private readonly int $duration = 0,
    ) {
    }

    /**
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
                $content = yield \Amp\File\read($this->filename);
            }
            if ($this->duration) {
                yield delay($this->duration * 1000);
            }
            //The date() function does not support microseconds, whereas DateTime does.
            $now = new \DateTime('now');
            $content .= $now->format('U u') . ' - ' . serialize($job->getPayloadData()) . PHP_EOL;
            yield File\write($this->filename, $content);
        });
    }
}
