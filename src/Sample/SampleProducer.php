<?php

namespace Webgriffe\Esb\Sample;

use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\RepeatProducerInterface;

class SampleProducer implements RepeatProducerInterface
{
    /**
     * @return string
     */
    public function getTube(): string
    {
        return SampleWorker::TUBE;
    }

    /**
     * @return void
     */
    public function init(): void
    {
        // No init needed.
    }

    /**
     * @return \Generator
     * @throws \RuntimeException
     */
    public function produce(): \Generator
    {
        $dir = '/tmp/sample_producer';
        if (!is_dir($dir)) {
            if (!mkdir($dir) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
            }
        }
        $files = scandir($dir, SCANDIR_SORT_NONE);
        foreach ($files as $file) {
            $file = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($file)) {
                continue;
            }
            yield serialize(['file' => $file, 'data' => file_get_contents($file)]);
        }
    }

    /**
     * @param Job $job
     * @return void
     */
    public function onProduceSuccess(Job $job)
    {
        $payload = $job->getPayloadData();
        unlink($payload['file']);
    }

    /**
     * @param Job $job
     * @param \Exception $exception
     * @return void
     */
    public function onProduceFail(Job $job, \Exception $exception)
    {
    }

    /**
     * @return int
     */
    public function getInterval(): int
    {
        return 1000;
    }
}
