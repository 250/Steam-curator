<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\RecommendationState;

final class CuratorReview
{
    public int $appId;
    public string $appName;
    public string $blurb;
    public RecommendationState $recommendationState;

    public Ranking $ranking {
        get {
            if (preg_match('[was a member of the (.+?) until]', $this->blurb, $matches)) {
                return $matches[1] === 'Hidden Gems' ? Ranking::HiddenGems : Ranking::Top250;
            }
        }
    }

    public \DateTimeImmutable $lastRecommended {
        get => \DateTimeImmutable::createFromFormat(
            ReviewSynchronizer::DATE_FORMAT, preg_replace('[.* until ]', '', $this->blurb)
        );
    }

    public static function fromArray(array $review): self
    {
        $new = new self();
        $new->appId = $review['appid'];
        $new->appName = $review['app_name'];
        $new->blurb = $review['recommendation']['blurb'];
        $new->recommendationState = RecommendationState::fromInt($review['recommendation']['recommendation_state']);

        return $new;
    }
}
