<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp\Loop;
use Monolog\Logger;
use Webgriffe\Esb\Flow;

class FlowManager
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Flow[]
     */
    private $flows = [];

    /**
     * FlowManager constructor.
     * @param Logger $logger
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function bootFlows()
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

    /**
     * @param \Webgriffe\Esb\Flow $flow
     */
    public function addFlow(Flow $flow)
    {
        $this->flows[] = $flow;
    }
}
