<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

use Eloquent\Enumeration\AbstractMultiton;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorList\CuratorList;

/**
 * @method static self[] members()
 */
final class Ranking extends AbstractMultiton
{
    private $id;

    private $priority;

    private $cName;

    private $description;

    private $ratingDescription;

    private $urlPath;

    protected function __construct(
        string $id,
        int $priority,
        string $cName,
        string $description,
        string $ratedDescription,
        string $urlPath
    ) {
        parent::__construct($id);

        $this->id = $id;
        $this->priority = $priority;
        $this->cName = $cName;
        $this->description = $description;
        $this->ratingDescription = $ratedDescription;
        $this->urlPath = $urlPath;
    }

    protected static function initializeMembers(): void
    {
        new static(
            'index',
            $priority = 0,
            'Steam Top 250',
            'Top 100 best Steam games of all time according to gamer reviews.
            For the complete Top 250 ranking visit steam250.com.',
            'Steam game of all time',
            '/'
        );

        new static(
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

        throw new \RuntimeException("No ranking found matching URL: \"$url\".");
    }

    public function __toString()
    {
        return $this->id;
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
