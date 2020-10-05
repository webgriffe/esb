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
     * @var array<string, mixed>
     */
    private $config;

    /**
     * @param string $tube
     * @param array<string, mixed> $config
     */
    public function __construct(string $tube, array $config)
    {
        $this->tube = $tube;
        $this->config = $config;
    }

    public function getName(): string
    {
        //We may introduce an explicit name for a flow at a later time. For now use the tube name as the flow name
        return $this->getTube();
    }

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
        //error_retry_delay is the new name, so give precedence to that
        return $this->config['worker']['error_retry_delay'] ?: $this->config['worker']['release_delay'];
    }

    public function getWorkerMaxRetry(): int
    {
        return $this->config['worker']['max_retry'];
    }

    /**
     * @return array<string>
     */
    public function getDependsOn(): array
    {
        return $this->config['dependencies']['flows'];
    }

    public function getDelayAfterIdleTime(): int
    {
        return $this->config['dependencies']['delay_after_idle_time'];
    }

    public function getInitialPollingInterval(): int
    {
        return $this->config['dependencies']['initial_polling_interval'];
    }

    public function getMaximumPollingInterval(): int
    {
        return $this->config['dependencies']['maximum_polling_interval'];
    }

    public function getPollingIntervalMultiplier(): float
    {
        return $this->config['dependencies']['polling_interval_multiplier'];
    }

    public function getProducerBatchSize(): int
    {
        return $this->config['producer']['batch_size'];
    }
}
