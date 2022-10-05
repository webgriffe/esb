<?php

namespace Webgriffe\Esb;

use Amp\Loop;
use Amp\File;
use Amp\Producer;
use Amp\Promise;
use Amp\Success;
use Amp\Deferred;
use Amp\Iterator;
use Webgriffe\Esb\Model\Job;

/**
 * Sample repeat producer which produces a job for every file found in a given directory.
 */
final class DummyFilesystemRepeatProducer implements RepeatProducerInterface
{
    /**
     * @var string
     */
    private $directory;
    /**
     * @var int
     */
    private $interval;
    /**
     * @var int
     */
    private $produceDelay;

    public function __construct(string $directory, int $interval = 1, int $produceDelay = null)
    {
        $this->directory = $directory;
        $this->interval = $interval;
        $this->produceDelay = $produceDelay;
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
     * @param mixed $data
     * @return Iterator
     * @throws \Error
     */
    public function produce($data = null): Iterator
    {
        return new Producer(function (callable $emit) {
            if (!(yield File\isDirectory($this->directory))) {
                if (!(yield File\createDirectory($this->directory)) && !(yield File\createDirectory($this->directory))) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->directory));
                }
            }
            $files = yield File\listFiles($this->directory);
            foreach ($files as $file) {
                $file = $this->directory . DIRECTORY_SEPARATOR . $file;
                if (yield File\isDirectory($file)) {
                    continue;
                }
                yield $this->longRunningOperation();
                yield $emit(new Job(['file' => $file, 'data' => (yield File\read($file))]));
                yield \Amp\File\deleteFile($file);
            }
        });
    }

    /**
     * @return int
     */
    public function getInterval(): int
    {
        return $this->interval;
    }

    /**
     * @return Promise
     * @throws \Error
     */
    private function longRunningOperation(): Promise
    {
        if (!$this->produceDelay) {
            return new Success(true);
        }
        $deferred = new Deferred();
        Loop::delay($this->produceDelay, function () use ($deferred) {
            $deferred->resolve(true);
        });
        return $deferred->promise();
    }
}
