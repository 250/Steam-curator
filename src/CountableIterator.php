<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

class CountableIterator implements \IteratorAggregate, \Countable
{
    public function __construct(private readonly int $count, private readonly \Iterator $iterator)
    {
    }

    public function getIterator(): \Traversable
    {
        return $this->iterator;
    }

    public function count(): int
    {
        return $this->count;
    }
}
