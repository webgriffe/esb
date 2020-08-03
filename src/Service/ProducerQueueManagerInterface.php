<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp\Promise;
use Webgriffe\Esb\Model\JobInterface;

interface ProducerQueueManagerInterface
{
    /**
     * Initializes this queue manager. Must be called before this can be used
     *
     * @return Promise<null>
     */
    public function boot(): Promise;

    /**
     * Adds a new job to the queue managed by this object. The method returns a promise that resolves to the number of
     * jobs that were actually added to the underlying queue. In the simplest case this number is 1, but if the
     * implementation uses some form of caching or bunching, then the return value may be 0 for several calls and be
     * some large value for a few calls, when the buffer is emptied.
     * To be sure that all jobs are actually pushed to the queue, use the flush() method after the last call to
     * enqueue()
     *
     * @param JobInterface $job The job to add to the queue
     * @return Promise<int>
     */
    public function enqueue(JobInterface $job): Promise;

    /**
     * Ensures that all buffered jobs are flushed to the underlying queue. Returns a promise that resolves to the number
     * of jobs that were added to the queue.
     *
     * @return Promise<int>
     */
    public function flush(): Promise;
}
