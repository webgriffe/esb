<?php

namespace Webgriffe\Esb\Service\Producer;

use Webgriffe\Esb\Service\Worker\SampleWorker;

class SampleProducer implements RepeatProducerInterface
{
    /**
     * @return string
     */
    public function getTube(): string
    {
        return SampleWorker::TUBE;
    }

    /**
     * @return array
     */
    public function produce(): array
    {
        $dir = '/tmp/sample_producer';
        if (!is_dir($dir)) {
            if (!mkdir($dir) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
            }
        }
        $files = scandir($dir, SCANDIR_SORT_NONE);
        $payloads = [];
        foreach ($files as $file) {
            $file = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($file)) {
                continue;
            }
            $payloads[] = serialize(['file' => $file, 'data' => file_get_contents($file)]);
        }
        return $payloads;
    }

    /**
     * @param string $payload
     * @return void
     */
    public function onProduceSuccess(string $payload)
    {
        $payload = unserialize($payload);
        unlink($payload['file']);
    }

    /**
     * @param string $payload
     * @param \Exception $exception
     * @return void
     */
    public function onProduceFail(string $payload, \Exception $exception)
    {
        // TODO: Implement onProduceFail() method.
    }

    /**
     * @return int
     */
    public function getInterval(): int
    {
        return 1000;
    }
}
