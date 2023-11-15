<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator\Import;

use ScriptFUSION\Porter\Connector\Recoverable\ExponentialAsyncDelayRecoverableExceptionHandler;
use ScriptFUSION\Porter\Connector\Recoverable\RecoverableException;
use ScriptFUSION\Porter\Connector\Recoverable\RecoverableExceptionHandler;
use ScriptFUSION\Porter\Net\Http\HttpServerException;
use ScriptFUSION\Retry\ExceptionHandler\AsyncExponentialBackoffExceptionHandler;

final class PutCuratorReviewExceptionHandler implements RecoverableExceptionHandler
{
    private RecoverableExceptionHandler $exceptionHandler;

    public function initialize(): void
    {
        $this->exceptionHandler = new ExponentialAsyncDelayRecoverableExceptionHandler(
            // Wait at least a minute before trying again.
            600 * AsyncExponentialBackoffExceptionHandler::DEFAULT_COEFFICIENT
        );
        $this->exceptionHandler->initialize();
    }

    public function __invoke(RecoverableException $exception): void
    {
        if ($exception instanceof HttpServerException && $exception->getCode() === 400) {
            $response = \json_decode($exception->getResponse()->getBody());
            if ($response->success === 8) {
                throw new \RuntimeException('Invalid param: app probably deleted.');
            }
        }

        ($this->exceptionHandler)($exception);
    }
}
