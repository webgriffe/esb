<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\call;
use Amp\Promise;
use RingCentral\Psr7\Response;

class IndexController
{
    /**
     * @var \Twig_Environment
     */
    private $twig;
    /**
     * @var BeanstalkClient
     */
    private $beanstalkClient;

    public function __construct(\Twig_Environment $twig, BeanstalkClient $beanstalkClient)
    {
        $this->twig = $twig;
        $this->beanstalkClient = $beanstalkClient;
    }

    /**
     * @return Promise
     */
    public function __invoke(): Promise
    {
        return call(function () {
            $tubes = yield array_map(
                function (string $tube) {
                    return $this->beanstalkClient->getTubeStats($tube);
                },
                yield $this->beanstalkClient->listTubes()
            );
            return new Response(200, [], $this->twig->render('index', array('tubes' => $tubes)));
        });
    }
}
