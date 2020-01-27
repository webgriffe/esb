<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Http\Server\Request;
use Twig\Environment;

abstract class AbstractController
{
    /**
     * @var Environment
     */
    private $twig;
    /**
     * @var BeanstalkClient
     */
    private $beanstalkClient;

    public function __construct(Environment $twig, BeanstalkClient $beanstalkClient)
    {
        $this->twig = $twig;
        $this->beanstalkClient = $beanstalkClient;
    }

    /**
     * @return Environment
     */
    public function getTwig(): Environment
    {
        return $this->twig;
    }

    /**
     * @return BeanstalkClient
     */
    public function getBeanstalkClient(): BeanstalkClient
    {
        return $this->beanstalkClient;
    }
}
