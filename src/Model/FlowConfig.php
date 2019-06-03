<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Model;

/**
 * @internal
 */
class FlowConfig
{
    /**
     * @var string
     */
    private $tube;
    /**
     * @var array
     */
    private $config;

    public function __construct(string $tube, array $config)
    {
        $this->tube = $tube;
        $this->config = $config;
    }

    /**
     * @return string
     */
    public function getTube(): string
    {
        return $this->tube;
    }

    public function getDescription(): string
    {
        return $this->config['description'];
    }

    public function getProducerServiceId(): string
    {
        return $this->config['producer']['service'];
    }

    public function getWorkerServiceId(): string
    {
        return $this->config['worker']['service'];
    }

    public function getWorkerInstancesCount(): int
    {
        return $this->config['worker']['instances'];
    }

    public function getWorkerReleaseDelay(): int
    {
        return $this->config['worker']['release_delay'];
    }

    public function getWorkerMaxRetry(): int
    {
        return $this->config['worker']['max_retry'];
    }
}
