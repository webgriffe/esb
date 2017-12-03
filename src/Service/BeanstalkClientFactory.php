<?php


namespace Webgriffe\Esb\Service;


use Amp\Beanstalk\BeanstalkClient;

class BeanstalkClientFactory
{
    /**
     * @var string
     */
    private $connectionUri;

    public function __construct(string $connectionUri)
    {
        $this->connectionUri = $connectionUri;
    }

    public function create()
    {
        return new BeanstalkClient($this->connectionUri);
    }
}
