<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Twig\Environment;
use Webgriffe\Esb\FlowManager;
use Webgriffe\Esb\Service\ElasticSearch;

abstract class AbstractController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly FlowManager $flowManager,
        private readonly ElasticSearch $elasticSearch,
    ) {
    }

    public function getTwig(): Environment
    {
        return $this->twig;
    }

    public function getFlowManager(): FlowManager
    {
        return $this->flowManager;
    }

    public function getElasticsearch(): ElasticSearch
    {
        return $this->elasticSearch;
    }
}
