<?php

declare(strict_types=1);

namespace ReactInspector\Tests\Socket;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ClientAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\Test;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use React\Socket\ServerInterface;
use React\Socket\SocketServer;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;

use function assert;
use function parse_url;
use function React\Async\await;

use const PHP_URL_PORT;

final class SocketInstrumentationTest extends AsyncTestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<int, ImmutableSpan> */
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;
    private ConnectorInterface $client;
    private string $clientAddress;
    private int $clientPort;
    private ServerInterface $server;
    private string $serverAddress;
    private int $serverPort;

    #[Before]
    public function resetBeforeNextTest(): void
    {
        $this->storage        = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage),
            ),
        );
        $this->scope          = Configurator::create()
            ->withTracerProvider($this->tracerProvider)
            ->withPropagator(new TraceContextPropagator())
            ->activate();

        $this->client = new Connector();
        $this->server = new SocketServer('127.0.0.1:0');
        $this->server->on('connection', function (ConnectionInterface $connection): void {
            $this->clientAddress = (string) $connection->getRemoteAddress();
            $this->clientPort    = (int) parse_url($this->clientAddress, PHP_URL_PORT);
            $this->server->close();
            $connection->on('data', static function (string $data) use ($connection): void {
                $connection->write($data);
            });
        });
        $this->serverAddress = (string) $this->server->getAddress();
        $this->serverPort    = (int) parse_url($this->serverAddress, PHP_URL_PORT);
    }

    #[After]
    public function detachScopeAfterTests(): void
    {
        $this->scope->detach();
    }

    #[Test]
    public function sendAndReceive(): void
    {
        self::assertCount(0, $this->storage);
        $connection = await($this->client->connect($this->serverAddress));
        $connection->on('data', static function (string $data) use ($connection): void {
            $connection->end();
        });
        $connection->write('ping');
        self::assertCount(4, $this->storage);
        $spanOne = $this->storage->offsetGet(0);
        assert($spanOne instanceof ImmutableSpan);
        $spanTwo = $this->storage->offsetGet(1);
        assert($spanTwo instanceof ImmutableSpan);
        $spanThree = $this->storage->offsetGet(2);
        assert($spanThree instanceof ImmutableSpan);
        $spanFour = $this->storage->offsetGet(3);
        assert($spanFour instanceof ImmutableSpan);
        self::assertSame('React\Socket\TcpConnector::connect: ' . $this->serverAddress, $spanOne->getName());
        self::assertSame('React\Socket\HappyEyeBallsConnector::connect: ' . $this->serverAddress, $spanTwo->getName());
        self::assertSame('React\Socket\TimeoutConnector::connect: ' . $this->serverAddress, $spanThree->getName());
        self::assertSame('React\Socket\Connector::connect: ' . $this->serverAddress, $spanFour->getName());
        self::assertSame('127.0.0.1', $spanOne->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
        self::assertSame('127.0.0.1', $spanTwo->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
        self::assertSame('127.0.0.1', $spanThree->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
        self::assertSame('127.0.0.1', $spanFour->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
        self::assertSame($this->serverPort, $spanOne->getAttributes()->get(ServerAttributes::SERVER_PORT));
        self::assertSame($this->serverPort, $spanTwo->getAttributes()->get(ServerAttributes::SERVER_PORT));
        self::assertSame($this->serverPort, $spanThree->getAttributes()->get(ServerAttributes::SERVER_PORT));
        self::assertSame($this->serverPort, $spanFour->getAttributes()->get(ServerAttributes::SERVER_PORT));
        self::assertSame('127.0.0.1', $spanOne->getAttributes()->get(ClientAttributes::CLIENT_ADDRESS));
        self::assertSame('127.0.0.1', $spanTwo->getAttributes()->get(ClientAttributes::CLIENT_ADDRESS));
        self::assertSame('127.0.0.1', $spanThree->getAttributes()->get(ClientAttributes::CLIENT_ADDRESS));
        self::assertSame('127.0.0.1', $spanFour->getAttributes()->get(ClientAttributes::CLIENT_ADDRESS));
        self::assertSame($this->clientPort, $spanOne->getAttributes()->get(ClientAttributes::CLIENT_PORT));
        self::assertSame($this->clientPort, $spanTwo->getAttributes()->get(ClientAttributes::CLIENT_PORT));
        self::assertSame($this->clientPort, $spanThree->getAttributes()->get(ClientAttributes::CLIENT_PORT));
        self::assertSame($this->clientPort, $spanFour->getAttributes()->get(ClientAttributes::CLIENT_PORT));
    }
}
