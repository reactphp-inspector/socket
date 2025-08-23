<?php

declare(strict_types=1);

namespace ReactInspector\Socket;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStreamInterface;

use function spl_object_id;

// phpcs:disable
final class ContextAwareConnection implements ConnectionInterface
{
    /**
     * @var array<int, callable>
     */
    private array $listenerMap = [];

    public function __construct(private readonly ConnectionInterface $connection, private readonly ContextInterface $context)
    {
        $this->connection->on('close', function (): void {
            $scope = Context::storage()->scope();
            if ($scope instanceof ContextStorageScopeInterface) {
                $scope->detach();
            }

            Span::fromContext($this->context)->end();
        });
    }

    public function getRemoteAddress()
    {
        return $this->connection->getRemoteAddress();
    }

    public function getLocalAddress()
    {
        return $this->connection->getLocalAddress();
    }

    public function on($event, callable $listener)
    {
        return $this->connection->on($event, $this->wrapListener($listener));
    }

    public function once($event, callable $listener)
    {
        return $this->connection->once($event, $this->wrapListener($listener));
    }

    public function removeListener($event, callable $listener)
    {
        return $this->connection->removeListener($event, $this->listenerMap[spl_object_id($listener)]);
    }

    public function removeAllListeners($event = null)
    {
        return $this->connection->removeAllListeners($event);
    }

    public function listeners($event = null)
    {
        return $this->connection->listeners($event);
    }

    public function emit($event, array $arguments = [])
    {
        return $this->connection->emit($event, $arguments);
    }

    public function isReadable()
    {
        return $this->connection->isReadable();
    }

    public function pause()
    {
        return $this->connection->pause();
    }

    public function resume()
    {
        return $this->connection->resume();
    }

    public function pipe(WritableStreamInterface $dest, array $options = [])
    {
        return $this->connection->pipe($dest, $options);
    }

    public function close()
    {
        return $this->connection->close();
    }

    public function isWritable()
    {
        return $this->connection->isWritable();
    }

    public function write($data)
    {
        return $this->connection->write($data);
    }

    public function end($data = null)
    {
        return $this->connection->end($data);
    }

    private function wrapListener(callable $listener): callable
    {
        $wrapper = function (...$args) use ($listener): void {
            $scope = Context::storage()->attach($this->context);

            $listener(...$args);

            $scope->detach();
        };

        $this->listenerMap[spl_object_id($listener)] = $wrapper;

        return $wrapper;
    }
}
