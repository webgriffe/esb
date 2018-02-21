<?php

namespace Webgriffe\Esb;

use Amp\Promise;
use Amp\Success;
use Webgriffe\Esb\Model\Job;

/**
 * Sample repeat producer which produces a job for every file found in a given directory.
 */
class DummyFilesystemRepeatProducer implements RepeatProducerInterface
{
    /**
     * @var string
     */
    private $directory;

    public function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    /**
     * @return string
     */
    public function getTube(): string
    {
        return DummyFilesystemWorker::TUBE;
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
     * @return \Generator
     * @throws \RuntimeException
     */
    public function produce($data = null): \Generator
    {
        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory) && !is_dir($this->directory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->directory));
            }
        }
        $files = scandir($this->directory, SCANDIR_SORT_NONE);
        foreach ($files as $file) {
            $file = $this->directory . DIRECTORY_SEPARATOR . $file;
            if (is_dir($file)) {
                continue;
            }
            yield new Job(['file' => $file, 'data' => file_get_contents($file)]);
            unlink($file);
        }
    }

    /**
     * @return int
     */
    public function getInterval(): int
    {
        return 1;
    }
}
