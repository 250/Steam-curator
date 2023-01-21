<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

use Eloquent\Enumeration\AbstractMultiton;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorList\CuratorList;

final class Ranking extends AbstractMultiton
{
    protected function __construct(
        private readonly string $id,
        private readonly int $priority,
        private readonly string $cName,
        private readonly string $description,
        private readonly string $ratingDescription,
        private readonly string $urlPath
    ) {
        parent::__construct($id);
    }

    protected static function initializeMembers(): void
    {
        new self(
            'top250',
            $priority = 0,
            'Steam Top 250',
            'Top 100 best Steam games of all time according to gamer reviews.
            For the complete Top 250 ranking visit steam250.com.',
            'Steam game of all time',
            '/top250'
        );

        new self(
            'hidden_gems',
            ++$priority,
            'Hidden Gems',
            'Top 100 highly rated Steam games that few know but many love.',
            'Steam Hidden Gem',
            '/hidden_gems'
        );
    }

    public static function fromUrl(string $url): Ranking
    {
        foreach (self::members() as $ranking) {
            if (strpos($url, "{$ranking->getUrlPath()}#")) {
                return $ranking;
            }
        }

        // Old format, when Top 250 ranking was the home page.
        if (str_starts_with($url, 'https://steam250.com/#app')) {
            return self::memberByKey('top250');
        }

        throw new \RuntimeException("No ranking found matching URL: \"$url\".");
    }

    public function toCuratorList(): CuratorList
    {
        $list = new CuratorList;
        $list->setTitle($this->getCanonicalName());
        $list->setDescription($this->getDescription());

        return $list;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getCanonicalName(): string
    {
        return $this->cName;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getRatingDescription(): string
    {
        return $this->ratingDescription;
    }

    public function getUrlPath(): string
    {
        return $this->urlPath;
    }
}
