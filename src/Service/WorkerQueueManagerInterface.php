<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp\Promise;
use Webgriffe\Esb\Model\JobInterface;

interface WorkerQueueManagerInterface
{
    /**
     * Initializes this queue manager. Must be called before this can be used
     *
     * @return Promise
     */
    public function boot(): Promise;

    /**
     * Gets the first ready job in the queue. If no job is ready, then this call waits until one becomes available.
     * Returns a promise that resolves to a JobInterface object
     *
     * @return Promise
     */
    public function getNextJob(): Promise;

    /**
     * Updates the data of the specified job in the queue
     *
     * @param JobInterface $job
     * @return Promise
     */
    public function updateJob(JobInterface $job): Promise;

    /**
     * Puts the specified job back in the queue from where it was extracted. If a delay greater than 0 is specified,
     * then the job will not become available for reprocessing until that delay has elapsed.
     *
     * @param JobInterface $job
     * @param int $delay
     * @return Promise
     */
    public function requeue(JobInterface $job, int $delay = 0): Promise;

    /**
     * Removes a job from the queue
     *
     * @param JobInterface $job
     * @return Promise
     */
    public function dequeue(JobInterface $job): Promise;

    /**
     * Checks whether a given queue is empty. It can be any queue, not just the one that this queue manager works with.
     * Returns a promise that resolves to a boolean value
     *
     * @param string $queueName
     * @return Promise
     */
    public function isEmpty(string $queueName): Promise;
}
