<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Model;

class QueuedJob
{
    /**
     * @var int
     */
    private $id;

    /**
     * @var array
     */
    private $payloadData;

    /**
     * QueuedJob constructor.
     * @param int $id
     * @param array $payloadData
     */
    public function __construct(int $id, array $payloadData)
    {
        $this->id = $id;
        $this->payloadData = $payloadData;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return array
     */
    public function getPayloadData(): array
    {
        return $this->payloadData;
    }
}
