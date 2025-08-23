<?php

declare(strict_types=1);

namespace ReactInspector\Socket;

use Composer\InstalledVersions;
use Evenement\EventEmitterInterface;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\SemConv\Attributes\ClientAttributes;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use React\Promise\PromiseInterface;
use React\Socket\Connection;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Socket\ServerInterface;
use Throwable;

use function array_key_exists;
use function assert;
use function is_array;
use function is_callable;
use function is_string;
use function OpenTelemetry\Instrumentation\hook;
use function parse_url;
use function sprintf;

final class SocketInstrumentation
{
    public const string NAME = 'reactphp';

    /**
     * The name of the Composer package.
     *
     * @see https://getcomposer.org/doc/04-schema.md#name
     */
    private const string COMPOSER_NAME = 'react-inspector/socket';

    /**
     * Name of this instrumentation library which provides the instrumentation for Bunny.
     *
     * @see https://opentelemetry.io/docs/specs/otel/glossary/#instrumentation-library
     */
    private const string INSTRUMENTATION_LIBRARY_NAME = 'io.opentelemetry.contrib.php.react-socket';

    /** @phpstan-ignore shipmonk.deadMethod */
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            self::INSTRUMENTATION_LIBRARY_NAME,
            InstalledVersions::getPrettyVersion(self::COMPOSER_NAME),
            Version::VERSION_1_32_0->url(),
        );

        self::registerClient($instrumentation);
        self::registerServer($instrumentation);
    }

    private static function registerClient(CachedInstrumentation $instrumentation): void
    {
        hook(
            ConnectorInterface::class,
            'connect',
            pre: static function (
                ConnectorInterface $connector,
                array $params,
                string $class,
                string $function,
                string|null $filename,
                int|null $lineno,
            ) use ($instrumentation): void {
                [$hostName] = $params;
                assert(is_string($hostName));

                $parentContext = Context::getCurrent();

                $spanBuilder = $instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('%s::%s', $class, $function) . ': ' . $hostName)
                    ->setParent($parentContext)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    // code
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);

                $span    = $spanBuilder->startSpan();
                $context = $span->storeInContext($parentContext);

                Context::storage()->attach($context);
            },
            post: static function (
                ConnectorInterface $connector,
                array $params,
                PromiseInterface $promise,
            ): PromiseInterface {
                $scope = Context::storage()->scope();
                if (! $scope instanceof ContextStorageScopeInterface) {
                    return $promise;
                }

                $scope->detach();
                $span = Span::fromContext($scope->context());
                if (! $span->isRecording()) {
                    return $promise;
                }

                return $promise->then(static function (mixed $stuff) use ($span): mixed {
                    if ($stuff instanceof ConnectionInterface) {
                        $parsedLocalAddress = parse_url((string) $stuff->getLocalAddress());
                        if (is_array($parsedLocalAddress)) {
                            if (array_key_exists('host', $parsedLocalAddress)) {
                                $span = $span->setAttribute(ClientAttributes::CLIENT_ADDRESS, $parsedLocalAddress['host']);
                            }

                            if (array_key_exists('port', $parsedLocalAddress)) {
                                $span = $span->setAttribute(ClientAttributes::CLIENT_PORT, $parsedLocalAddress['port']);
                            }
                        }

                        $parsedRemoteAddress = parse_url((string) $stuff->getRemoteAddress());
                        if (is_array($parsedRemoteAddress)) {
                            if (array_key_exists('host', $parsedRemoteAddress)) {
                                $span = $span->setAttribute(ServerAttributes::SERVER_ADDRESS, $parsedRemoteAddress['host']);
                            }

                            if (array_key_exists('port', $parsedRemoteAddress)) {
                                $span = $span->setAttribute(ServerAttributes::SERVER_PORT, $parsedRemoteAddress['port']);
                            }
                        }

                        /** @phpstan-ignore property.internal,instanceof.internalClass */
                        if ($stuff instanceof Connection && $stuff->encryptionEnabled === true) {
                            /** @phpstan-ignore classConstant.deprecatedInterface */
                            $span = $span->setAttribute(TraceAttributes::TLS_ESTABLISHED, true);
                        }
                    }

                    $span->end();

                    return $stuff;
                }, static function (Throwable $exception) use ($span): never {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                    $span->end();

                    /** @phpstan-ignore shipmonk.checkedExceptionInCallable */
                    throw $exception;
                });
            },
        );
    }

    private static function registerServer(CachedInstrumentation $instrumentation): void
    {
        hook(
            EventEmitterInterface::class,
            'on',
            pre: static function (
                object $server,
                array $params,
                string $class,
                string $function,
                string|null $filename,
                int|null $lineno,
            ) use ($instrumentation): array {
                if (! $server instanceof ServerInterface) {
                    return $params;
                }

                [$event, $handler] = $params;

                assert(is_string($event));
                if ($event !== 'connection') {
                    return $params;
                }

                assert(is_callable($handler));

                $params[1] = static function (ConnectionInterface $connection) use ($handler, $instrumentation, $class, $function, $filename, $lineno): void {
                    $parentContext = Context::getCurrent();

                    $spanBuilder = $instrumentation
                        ->tracer()
                        ->spanBuilder(sprintf('%s::%s', $class, $function) . ': connection received')
                        ->setParent($parentContext)
                        ->setSpanKind(SpanKind::KIND_INTERNAL)
                        // code
                        ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                        ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                        ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);

                    $parsedRemoteAddress = parse_url((string) $connection->getRemoteAddress());
                    if (is_array($parsedRemoteAddress)) {
                        if (array_key_exists('host', $parsedRemoteAddress)) {
                            $spanBuilder = $spanBuilder->setAttribute(ClientAttributes::CLIENT_ADDRESS, $parsedRemoteAddress['host']);
                        }

                        if (array_key_exists('port', $parsedRemoteAddress)) {
                            $spanBuilder = $spanBuilder->setAttribute(ClientAttributes::CLIENT_PORT, $parsedRemoteAddress['port']);
                        }
                    }

                    $parsedLocalAddress = parse_url((string) $connection->getLocalAddress());
                    if (is_array($parsedLocalAddress)) {
                        if (array_key_exists('host', $parsedLocalAddress)) {
                            $spanBuilder = $spanBuilder->setAttribute(ServerAttributes::SERVER_ADDRESS, $parsedLocalAddress['host']);
                        }

                        if (array_key_exists('port', $parsedLocalAddress)) {
                            $spanBuilder = $spanBuilder->setAttribute(ServerAttributes::SERVER_PORT, $parsedLocalAddress['port']);
                        }
                    }

                    /** @phpstan-ignore property.internal,instanceof.internalClass */
                    if ($connection instanceof Connection && $connection->encryptionEnabled === true) {
                        /** @phpstan-ignore classConstant.deprecatedInterface */
                        $spanBuilder = $spanBuilder->setAttribute(TraceAttributes::TLS_ESTABLISHED, true);
                    }

                    $span    = $spanBuilder->startSpan();
                    $context = $span->storeInContext($parentContext);

                    Context::storage()->attach($context);
                    $handler(new ContextAwareConnection($connection, $context));
                    Context::storage()->scope()?->detach();
                };

                return $params;
            },
        );
    }
}
