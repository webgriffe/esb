<?php

namespace Webgriffe\Esb;

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
     * @return void
     */
    public function init()
    {
        // No init needed.
    }

    /**
     * @return \Generator
     * @throws \RuntimeException
     */
    public function produce(): \Generator
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
     * @param Job $job
     * @return void
     */
    public function onProduceSuccess(Job $job)
    {
    }

    /**
     * @param Job $job
     * @param \Exception $exception
     * @return void
     */
    public function onProduceFail(Job $job, \Throwable $exception)
    {
    }

    /**
     * @return int
     */
    public function getInterval(): int
    {
        return 1;
    }
}
