<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Http\Server\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;

trait ControllerTrait
{
    /**
     * @var Request
     */
    private $request;
    /**
     * @var ContainerInterface
     */
    private $container;
    /**
     * @var \Twig_Environment
     */
    private $twig;
    /**
     * @var BeanstalkClient
     */
    private $beanstalkClient;

    public function __construct(Request $request, ContainerInterface $container)
    {
        $this->request = $request;
        $this->container = $container;
        $this->twig = $this->container->get('console.twig');
        $this->beanstalkClient = $this->container->get(BeanstalkClient::class);
    }
}
