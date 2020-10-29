<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Exception;

class HttpResponseException extends \Exception
{
    /**
     * @var int
     */
    private $httpResponseCode;

    /**
     * @var string
     */
    private $clientMessage;

    /**
     * @param int $httpResponseCode The HTTP response code to use
     * @param string $clientMessage The message to send to the client
     * @param string $internalMessage The message to use internally (e.g. store in error logs), when empty the client message is used
     * @param int $code The Exception code.
     * @param \Throwable|null $previous The previous throwable used for the exception chaining.
     */
    public function __construct(
        int $httpResponseCode,
        string $clientMessage,
        string $internalMessage = "",
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $this->httpResponseCode = $httpResponseCode;
        $this->clientMessage = $clientMessage;
        parent::__construct($internalMessage ?: $clientMessage, $code, $previous);
    }

    /**
     * @return int
     */
    public function getHttpResponseCode(): int
    {
        return $this->httpResponseCode;
    }

    /**
     * @return string
     */
    public function getClientMessage(): string
    {
        return $this->clientMessage;
    }
}
