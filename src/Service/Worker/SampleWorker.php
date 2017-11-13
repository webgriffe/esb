<?php

namespace Webgriffe\Esb\Service\Worker;

use Amp\Beanstalk\BeanstalkClient;

/**
 * This is a sample worker which simply writes job data to the /tmp/sample_worker.data file
 */
class SampleWorker implements WorkerInterface
{
    const TUBE = 'sample_tube';

    /**
     * @var BeanstalkClient
     */
    private $beanstalk;

    /**
     * SampleWorker constructor.
     * @param BeanstalkClient $beanstalkClient
     */
    public function __construct(BeanstalkClient $beanstalkClient)
    {
        $this->beanstalk = $beanstalkClient;
    }

    public function work()
    {
        yield $this->beanstalk->watch(self::TUBE);
        yield $this->beanstalk->ignore('default');
        $filename = '/tmp/sample_worker.data';
        touch($filename);
        while ($job = yield $this->beanstalk->reserve()) {
            file_put_contents($filename, date('c') . ' - ' . $job[1] . PHP_EOL, FILE_APPEND);
            $this->beanstalk->delete($job[0]);
        }
    }
}
