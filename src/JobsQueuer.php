<?php

namespace Webgriffe\Esb;

use Amp\Beanstalk\BeanstalkClient;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Model\Job;

class JobsQueuer
{
    /**
     * @param BeanstalkClient $beanstalkClient
     * @param ProducerInterface $producer
     * @param mixed $data
     * @return int
     */
    public static function queueJobs(
        BeanstalkClient $beanstalkClient,
        LoggerInterface $logger,
        ProducerInterface $producer,
        $data = null
    ): int {
        $jobsCount = 0;
        $jobs = $producer->produce($data);
        /** @var Job $job */
        foreach ($jobs as $job) {
            $payload = serialize($job->getPayloadData());
            $beanstalkClient->put($payload)->onResolve(
                function (\Throwable $error = null, int $jobId) use ($producer, $job, $logger) {
                    if ($error) {
                        $logger->error(
                            'An error occurred producing a job.',
                            [
                                'producer' => \get_class($producer),
                                'payload_data' => $job->getPayloadData(),
                                'error' => $error->getMessage(),
                            ]
                        );
                    } else {
                        $logger->info(
                            'Successfully produced a new Job',
                            [
                                'producer' => \get_class($producer),
                                'job_id' => $jobId,
                                'payload_data' => $job->getPayloadData()
                            ]
                        );
                    }
                }
            );
            $jobsCount++;
        }
        return $jobsCount;
    }
}
