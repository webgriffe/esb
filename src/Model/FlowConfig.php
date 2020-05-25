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

    public function getDependsOn(): array
    {
        return $this->config['depends_on'];
    }

    public function getDelayAfterIdleTime(): int
    {
        return $this->config['delay_after_idle_time'];
    }

    public function getInitialPollingInterval(): int
    {
        return $this->config['initial_polling_interval'];
    }

    public function getMaximumPollingInterval(): int
    {
        return $this->config['maximum_polling_interval'];
    }

    public function getPollingIntervalMultiplier(): float
    {
        return $this->config['polling_interval_multiplier'];
    }
}
