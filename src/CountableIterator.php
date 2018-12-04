<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

use Amp\Iterator;
use Amp\Promise;

class CountableIterator implements Iterator, \Countable
{
    private $count;

    private $iterator;

    public function __construct(int $count, Iterator $iterator)
    {
        $this->count = $count;
        $this->iterator = $iterator;
    }

    public function advance(): Promise
    {
        return $this->iterator->advance();
    }

    public function getCurrent()
    {
        return $this->iterator->getCurrent();
    }

    public function count(): int
    {
        return $this->count;
    }
}
