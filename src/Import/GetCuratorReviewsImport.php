<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator\Import;

use ScriptFUSION\Porter\Collection\FilteredRecords;
use ScriptFUSION\Porter\Collection\RecordCollection;
use ScriptFUSION\Porter\Import\Import;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorSession;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\GetCuratorReviews;
use ScriptFUSION\Porter\Transform\Transformer;
use ScriptFUSION\Steam250\Curator\CuratorReview;

final class GetCuratorReviewsImport extends Import
{
    public function __construct(private readonly CuratorSession $session, private readonly int $curatorId)
    {
        parent::__construct(new GetCuratorReviews($this->session, $this->curatorId));

        $this->addTransformer(
            new class implements Transformer {
                public function transform(RecordCollection $records, mixed $context): RecordCollection
                {
                    return new FilteredRecords((static function () use ($records) {
                        foreach ($records as $record) {
                            yield CuratorReview::fromArray($record);
                        }
                    })(), $records, (__METHOD__)(...));
                }
            }
        );
    }
}
