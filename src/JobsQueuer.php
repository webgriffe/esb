<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Promise;
use Amp\Beanstalk\BeanstalkClient;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Model\Job;
use function Amp\call;

class JobsQueuer
{
    /**
     * @param BeanstalkClient $beanstalkClient
     * @param LoggerInterface $logger
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
                    $jobId = yield $beanstalkClient->put(
                        $payload,
                        $job->getTimeout(),
                        $job->getDelay(),
                        $job->getPriority()
                    );
                    $logger->info(
                        'Successfully produced a new Job',
                        [
                            'producer' => \get_class($producer),
                            'job_id' => $jobId,
                            'payload_data' => NonUtf8Cleaner::clean($job->getPayloadData())
                        ]
                    );
                    $jobsCount++;
                } catch (\Throwable $error) {
                    $logger->error(
                        'An error occurred producing a job.',
                        [
                            'producer' => \get_class($producer),
                            'payload_data' => NonUtf8Cleaner::clean($job->getPayloadData()),
                            'error' => $error->getMessage(),
                        ]
                    );
                }
            }
            return $jobsCount;
        });
    }
}
