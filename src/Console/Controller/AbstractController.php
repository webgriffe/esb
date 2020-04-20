<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Twig\Environment;
use Webgriffe\AmpElasticsearch\Client;
use Webgriffe\Esb\FlowManager;
use Webgriffe\Esb\Service\ElasticSearch;

abstract class AbstractController
{
    /**
     * @var Environment
     */
    private $twig;
    /**
     * @var FlowManager
     */
    private $flowManager;
    /**
     * @var ElasticSearch
     */
    private $elasticSearch;

    public function __construct(
        Environment $twig,
        FlowManager $flowManager,
        ElasticSearch $elasticSearch
    ) {
        $this->twig = $twig;
        $this->flowManager = $flowManager;
        $this->elasticSearch = $elasticSearch;
    }

    /**
     * @return Environment
     */
    public function getTwig(): Environment
    {
        return $this->twig;
    }

    /**
     * @return FlowManager
     */
    public function getFlowManager(): FlowManager
    {
        return $this->flowManager;
    }

    /**
     * @return ElasticSearch
     */
    public function getElasticsearch(): ElasticSearch
    {
        return $this->elasticSearch;
    }
}
