<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

final class SyncReviewsStatus
{
    private $succeeded;

    private $skipped;

    private $errors;

    public function __construct(array $succeeded, array $skipped, array $errors)
    {
        $this->succeeded = $succeeded;
        $this->skipped = $skipped;
        $this->errors = $errors;
    }

    public function getSucceeded(): array
    {
        return $this->succeeded;
    }

    public function getSkipped(): array
    {
        return $this->skipped;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
