<?php

namespace Webgriffe\Esb\Sample;

use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\WorkerInterface;

/**
 * This is a sample worker which simply writes job data to the /tmp/sample_worker.data file
 */
class SampleWorker implements WorkerInterface
{
    const TUBE = 'sample_tube';

    /**
     * @return string
     */
    public function getTube(): string
    {
        return self::TUBE;
    }

    public function work(Job $job)
    {
        $filename = '/tmp/sample_worker.data';
        file_put_contents($filename, date('c') . ' - ' . $job->getPayload() . PHP_EOL, FILE_APPEND);
    }
}
