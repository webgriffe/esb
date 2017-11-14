<?php

namespace Webgriffe\Esb\Model;

class Job
{
    /**
     * @var int
     */
    private $id;

    /**
     * @var string
     */
    private $payload;

    /**
     * Job constructor.
     * @param int $id
     * @param string $payload
     */
    public function __construct(int $id, string $payload)
    {
        $this->id = $id;
        $this->payload = $payload;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getPayload(): string
    {
        return $this->payload;
    }
}
