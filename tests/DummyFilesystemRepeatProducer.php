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
    public function __construct(
        private readonly string $directory,
        private readonly int $interval = 1,
        private readonly ?int $produceDelay = null,
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
     * @throws \Error
     */
    public function produce(mixed $data = null): Iterator
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
                $fileContent = yield File\read($file);
                $fileContentAsArray = json_decode($fileContent, true);
                $payloadData = is_array($fileContentAsArray) ?
                    $fileContentAsArray :
                    ['file' => $file, 'data' => $fileContent];
                yield $emit(new Job($payloadData));
                yield \Amp\File\deleteFile($file);
            }
        });
    }

    public function getInterval(): int
    {
        return $this->interval;
    }

    /**
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
