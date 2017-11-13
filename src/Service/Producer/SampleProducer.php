<?php

namespace Webgriffe\Esb\Service\Producer;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\File\isdir;
use Amp\Loop;
use Webgriffe\Esb\Service\Worker\SampleWorker;

class SampleProducer implements ProducerInterface
{
    /**
     * @var BeanstalkClient
     */
    private $beanstalk;

    /**
     * SampleProducer constructor.
     * @param BeanstalkClient $beanstalk
     */
    public function __construct(BeanstalkClient $beanstalk)
    {
        $this->beanstalk = $beanstalk;
    }

    /**
     * @return void
     * @throws \RuntimeException
     */
    public function produce()
    {
        Loop::repeat(1000, function () {
            $dir = '/tmp/sample_producer';
            if (!is_dir($dir)) {
                if (!mkdir($dir) && !is_dir($dir)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
                }
            }
            $files = scandir($dir, SCANDIR_SORT_NONE);
            foreach ($files as $file) {
                $file = $dir . DIRECTORY_SEPARATOR . $file;
                if (is_dir($file)) {
                    continue;
                }
                $data = file_get_contents($file);
                yield $this->beanstalk->use(SampleWorker::TUBE);
                $jobId = yield $this->beanstalk->put($data);
                if ($jobId) {
                    \Amp\File\unlink($file);
                }
            }
        });
    }
}
