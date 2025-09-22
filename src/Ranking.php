<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorList\CuratorList;

enum Ranking: string
{
    case Top250 = 'top250';
    case HiddenGems = 'hidden_gems';

    public static function fromUrl(string $url): Ranking
    {
        foreach (self::cases() as $ranking) {
            if (strpos($url, "{$ranking->getUrlPath()}#")) {
                return $ranking;
            }
        }

        // Old format, when Top 250 ranking was the home page.
        if (str_starts_with($url, 'https://steam250.com/#app')) {
            return self::Top250;
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
        return $this->value;
    }

    public function getPriority(): int
    {
        return match ($this) {
            self::Top250 => 0,
            self::HiddenGems => 1,
        };
    }

    public function getCanonicalName(): string
    {
        return match ($this) {
            self::Top250 => 'Steam Top 250',
            self::HiddenGems => 'Hidden Gems',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Top250 => 'Top 100 best Steam games of all time according to gamer reviews.'
                . ' For the complete Top 250 ranking visit steam250.com.',
            self::HiddenGems => 'Top 100 highly rated Steam games that few know but many love.',
        };
    }

    public function getRatingDescription(): string
    {
        return match ($this) {
            self::Top250 => 'Steam game of all time',
            self::HiddenGems => 'Steam Hidden Gem',
        };
    }

    public function getUrlPath(): string
    {
        return match ($this) {
            self::Top250 => '/top250',
            self::HiddenGems => '/hidden_gems',
        };
    }
}
