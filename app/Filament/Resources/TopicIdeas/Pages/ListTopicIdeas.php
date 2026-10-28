<?php

declare(strict_types=1);

namespace App\Filament\Resources\TopicIdeas\Pages;

use App\Filament\Resources\TopicIdeas\TopicIdeaActions;
use App\Filament\Resources\TopicIdeas\TopicIdeaResource;
use Filament\Resources\Pages\ListRecords;

final class ListTopicIdeas extends ListRecords
{
    protected static string $resource = TopicIdeaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TopicIdeaActions::suggest(),
        ];
    }
}
