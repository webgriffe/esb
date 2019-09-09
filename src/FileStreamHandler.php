<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Log\StreamHandler;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\HandlerInterface;
use Amp\File;
use Monolog\ResettableInterface;
use Psr\Log\LogLevel;
use Amp\Promise;

final class FileStreamHandler implements HandlerInterface, ResettableInterface
{
    /**
     * @var StreamHandler
     */
    private $streamHandler;

    /**
     * @param string $filePath
     * @param string $level
     * @param bool $bubble
     * @throws \Throwable
     */
    public function __construct(string $filePath, string $level = LogLevel::DEBUG, bool $bubble = true)
    {
        /** @var File\Handle $file */
        $file = Promise\wait(File\open($filePath, 'w'));
        $this->streamHandler = new StreamHandler($file, $level, $bubble);
    }

    /**
     * Checks whether the given record will be handled by this handler.
     *
     * This is mostly done for performance reasons, to avoid calling processors for nothing.
     *
     * Handlers should still check the record levels within handle(), returning false in isHandling()
     * is no guarantee that handle() will not be called, and isHandling() might not be called
     * for a given record.
     *
     * @param array $record Partial log record containing only a level key
     *
     * @return bool
     */
    public function isHandling(array $record)
    {
        return $this->streamHandler->isHandling($record);
    }

    /**
     * Handles a record.
     *
     * All records may be passed to this method, and the handler should discard
     * those that it does not want to handle.
     *
     * The return value of this function controls the bubbling process of the handler stack.
     * Unless the bubbling is interrupted (by returning true), the Logger class will keep on
     * calling further handlers in the stack with a given log record.
     *
     * @param array $record The record to handle
     * @return bool true means that this handler handled the record, and that bubbling is not permitted.
     *                        false means the record was either not processed or that this handler allows bubbling.
     */
    public function handle(array $record)
    {
        return $this->streamHandler->handle($record);
    }

    /**
     * Handles a set of records at once.
     *
     * @param array $records The records to handle (an array of record arrays)
     */
    public function handleBatch(array $records)
    {
        return $this->streamHandler->handleBatch($records);
    }

    /**
     * Adds a processor in the stack.
     *
     * @param callable $callback
     * @return self
     */
    public function pushProcessor($callback)
    {
        $this->streamHandler->pushProcessor($callback);
        return $this;
    }

    /**
     * Removes the processor on top of the stack and returns it.
     *
     * @return callable
     */
    public function popProcessor()
    {
        return $this->streamHandler->popProcessor();
    }

    /**
     * Sets the formatter.
     *
     * @param FormatterInterface $formatter
     * @return self
     */
    public function setFormatter(FormatterInterface $formatter)
    {
        $this->streamHandler->setFormatter($formatter);
        return $this;
    }

    /**
     * Gets the formatter.
     *
     * @return FormatterInterface
     */
    public function getFormatter()
    {
        return $this->streamHandler->getFormatter();
    }

    public function reset()
    {
        return $this->streamHandler->reset();
    }
}
