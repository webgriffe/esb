<?php

namespace Webgriffe\Esb;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\call;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Model\Job;

class JobsQueuer
{
    /**
     * @param BeanstalkClient $beanstalkClient
     * @param ProducerInterface $producer
     * @param mixed $data
     * @return Promise
     */
    public static function queueJobs(
        BeanstalkClient $beanstalkClient,
        LoggerInterface $logger,
        ProducerInterface $producer,
        $data = null
    ): Promise {
        return call(function () use ($beanstalkClient, $logger, $producer, $data) {
            $jobsCount = 0;
            $jobs = $producer->produce($data);
            while (yield $jobs->advance()) {
                /** @var Job $job */
                $job = $jobs->getCurrent();
                $payload = serialize($job->getPayloadData());
                try {
                    $jobId = yield $beanstalkClient->put($payload);
                    $logger->info(
                        'Successfully produced a new Job',
                        [
                            'producer' => \get_class($producer),
                            'job_id' => $jobId,
                            'payload_data' => $job->getPayloadData()
                        ]
                    );
                    $jobsCount++;
                } catch (\Throwable $error) {
                    $logger->error(
                        'An error occurred producing a job.',
                        [
                            'producer' => \get_class($producer),
                            'payload_data' => $job->getPayloadData(),
                            'error' => $error->getMessage(),
                        ]
                    );
                }
            }
            return $jobsCount;
        });
    }
}
