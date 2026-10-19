<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Loop;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
class FlowManager
{
    /** @var Flow[] */
    private array $flows = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function bootFlows(): void
    {
        Loop::defer(function () {
            if (!\count($this->flows)) {
                $this->logger->notice('No flow to start.');
                return;
            }

            foreach ($this->flows as $flow) {
                $flow->boot();
            }
        });
    }

    public function addFlow(Flow $flow): void
    {
        $this->flows[] = $flow;
    }

    /**
     * @return Flow[]
     */
    public function getFlows(): array
    {
        return $this->flows;
    }
}
