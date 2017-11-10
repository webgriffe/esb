<?php

namespace Webgriffe\Esb\Service\Worker;

use Pheanstalk\Job;
use Pheanstalk\PheanstalkInterface;
use Webgriffe\Esb\WorkerInterface;

/**
 * This is a sample worker which simply writes job data to the /tmp/sample_worker.data file
 */
class SampleWorker implements WorkerInterface
{
    const TUBE = 'sample_tube';

    /**
     * @var PheanstalkInterface
     */
    private $pheanstalk;

    /**
     * SampleWorker constructor.
     * @param PheanstalkInterface $pheanstalk
     */
    public function __construct(PheanstalkInterface $pheanstalk)
    {
        $this->pheanstalk = $pheanstalk;
    }

    public function work()
    {
        while (true) {
            /** @var Job $job */
            $job = $this->pheanstalk
                ->watch(self::TUBE)
                ->ignore(PheanstalkInterface::DEFAULT_TUBE)
                ->reserve();
            file_put_contents('/tmp/sample_worker.data', date('c') . ' - ' . $job->getData());
            $this->pheanstalk->delete($job);
        }
    }

    /**
     * @return string
     */
    public function getCode()
    {
        return 'sample_worker';
    }
}
