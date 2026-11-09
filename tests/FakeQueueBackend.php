<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Deferred;
use Amp\Failure;
use Amp\Promise;
use Amp\Success;
use Webgriffe\Esb\Model\JobInterface;
use Webgriffe\Esb\Service\QueueBackendInterface;

final class FakeQueueBackend implements QueueBackendInterface
{
    public bool $failUpdates = false;

    public bool $failDequeue = false;

    /** @var JobInterface[] */
    public array $requeuedJobs = [];

    /** @var JobInterface[] */
    public array $dequeuedJobs = [];

    /** @var Deferred<null> */
    private Deferred $drained;

    /**
     * @param JobInterface[] $jobs
     */
    public function __construct(private array $jobs)
    {
        $this->drained = new Deferred();
    }

    public function boot(): Promise
    {
        return new Success();
    }

    public function getNextJob(): Promise
    {
        if (count($this->jobs) === 0) {
            $this->drained->resolve();

            return (new Deferred())->promise();
        }

        return new Success(array_shift($this->jobs));
    }

    public function updateJob(JobInterface $job): Promise
    {
        if ($this->failUpdates) {
            return new Failure(new \RuntimeException('Update failed'));
        }

        return new Success();
    }

    public function requeue(JobInterface $job, int $delay = 0): Promise
    {
        $this->requeuedJobs[] = $job;

        return new Success();
    }

    public function dequeue(JobInterface $job): Promise
    {
        if ($this->failDequeue) {
            return new Failure(new \RuntimeException('Dequeue failed'));
        }
        $this->dequeuedJobs[] = $job;

        return new Success();
    }

    public function isEmpty(string $queueName): Promise
    {
        return new Success(true);
    }

    public function jobExists(string $jobUuid): Promise
    {
        return new Success(false);
    }

    public function enqueueJobs(array $jobs): Promise
    {
        return new Success(count($jobs));
    }

    /**
     * @return Promise<null>
     */
    public function drained(): Promise
    {
        return $this->drained->promise();
    }
}
