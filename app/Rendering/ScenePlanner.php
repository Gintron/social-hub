<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Actions\PrepareVariantMedia;
use App\Enums\ContentKind;
use App\Models\ContentItem;
use App\Models\PostDraft;

/**
 * The slides of a draft's video, in order, without rendering any of them.
 *
 * One item is told as its slide set — the hook, its card, the brand's call to action — because a single
 * three-second card is the shortest video a platform accepts and gives the viewer no reason to stay.
 * A digest opens with its cover, gives every item a card and closes with the brand's end card. An
 * explicit template keeps the plain shape: one slide per item.
 */
final class ScenePlanner
{
    public function __construct(private readonly TemplateRegistry $templates) {}

    /**
     * @return list<Scene>
     */
    public function forDraft(PostDraft $draft, ?string $templateKey = null): array
    {
        $items = $draft->contentItems;

        if ($templateKey !== null) {
            return $items->map(fn (ContentItem $item): Scene => new Scene($templateKey, Scene::CARD, $item))->values()->all();
        }

        if ($draft->kind !== PostDraft::KIND_DIGEST) {
            $item = $items->first();

            return array_map(
                fn (string $key): Scene => new Scene($key, $this->templates->roleOf($key), $item),
                $this->templates->slidesFor($item->kind, 'story'),
            );
        }

        $jobs = $items->every(fn (ContentItem $item): bool => $item->kind === ContentKind::Job);
        $scenes = [new Scene($jobs ? 'kinds/job-digest-cover-story' : PrepareVariantMedia::digestCover('story'), Scene::COVER)];

        foreach ($items as $item) {
            $scenes[] = new Scene($this->templates->storyFor($item->kind), Scene::CARD, $item);
        }

        $closing = $jobs ? 'kinds/job-cta-story' : $this->templates->closingFor('story');

        if ($closing !== null) {
            $scenes[] = new Scene($closing, Scene::CLOSING);
        }

        return $scenes;
    }
}
