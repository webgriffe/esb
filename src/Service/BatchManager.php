<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use function Amp\call;
use Amp\Promise;

use Webgriffe\Esb\Model\JobInterface;

final class BatchManager implements BatchManagerInterface
{
    /**
     * @var array<string, JobInterface>
     */
    private $batch = [];

    public function __construct(
        private readonly QueueBackendInterface $queueBackend,
        private readonly int $batchSize
    ) {
    }

    /**
     * @inheritdoc
     */
    public function enqueue(JobInterface $job): Promise
    {
        return call(function () use ($job) {
            $jobExists = yield $this->queueBackend->jobExists($job->getUuid());
            if ($jobExists) {
                throw new \RuntimeException(
                    sprintf(
                        'A job with UUID "%s" already exists but this should be a new job.',
                        $job->getUuid()
                    )
                );
            }
            $this->batch[$job->getUuid()] = $job;

            $count = count($this->batch);
            if ($count < $this->batchSize) {
                return 0;   //Number of jobs actually added to the queue
            }

            return yield $this->flush();
        });
    }

    /**
     * @inheritdoc
     */
    public function flush(): Promise
    {
        return call(function () {
            $jobsCount = count($this->batch);
            if ($jobsCount > 0) {
                yield $this->queueBackend->enqueueJobs($this->batch);
            }
            $this->batch = [];

            return $jobsCount;
        });
    }
}
