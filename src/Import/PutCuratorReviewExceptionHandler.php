<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator\Import;

use Psr\Log\LoggerInterface;
use ScriptFUSION\Porter\Connector\Recoverable\ExponentialAsyncDelayRecoverableExceptionHandler;
use ScriptFUSION\Porter\Connector\Recoverable\RecoverableException;
use ScriptFUSION\Porter\Connector\Recoverable\RecoverableExceptionHandler;
use ScriptFUSION\Porter\Net\Http\HttpServerException;
use ScriptFUSION\Retry\ExceptionHandler\AsyncExponentialBackoffExceptionHandler;

final class PutCuratorReviewExceptionHandler implements RecoverableExceptionHandler
{
    private RecoverableExceptionHandler $exceptionHandler;

    private LoggerInterface $logger;

    public function __construct(private int $appId)
    {
    }

    public function initialize(): void
    {
        $this->exceptionHandler = new ExponentialAsyncDelayRecoverableExceptionHandler(
            // Wait at least 10 seconds before trying again.
            100 * AsyncExponentialBackoffExceptionHandler::DEFAULT_COEFFICIENT
        );
        $this->exceptionHandler->initialize();
    }

    public function __invoke(RecoverableException $exception): void
    {
        $this->logger && $this->logger->info(
            "Retrying #$this->appId: " . get_debug_type($exception) . ": {$exception->getMessage()}"
        );

        if ($exception instanceof HttpServerException && $exception->getCode() === 400) {
            $response = \json_decode($exception->getResponse()->getBody());
            if ($response->success === 8) {
                throw new \RuntimeException('Invalid param: app probably deleted.');
            }
        }

        ($this->exceptionHandler)($exception);
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }
}
