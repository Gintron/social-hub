<?php

declare(strict_types=1);

namespace App\Filament\Resources\TopicIdeas\Pages;

use App\Actions\UpdateTopicScript;
use App\Filament\Resources\TopicIdeas\TopicIdeaActions;
use App\Filament\Resources\TopicIdeas\TopicIdeaResource;
use App\Filament\Support\ReadableFormWidth;
use App\Models\TopicIdea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class EditTopicIdea extends EditRecord
{
    use ReadableFormWidth;

    protected static string $resource = TopicIdeaResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->topic()->title;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $topic = $this->topic();

        return $topic->brand->personaSettings()->name.' · '.$topic->for_date->format('d.m.Y').' · '.$topic->status->label();
    }

    protected function getHeaderActions(): array
    {
        return [
            TopicIdeaActions::choose()->after(fn () => $this->refreshTopic()),
            TopicIdeaActions::approve()->after(fn () => $this->refreshTopic()),
            TopicIdeaActions::rewrite()->after(fn () => $this->refreshTopic()),
            TopicIdeaActions::reject()->after(fn () => $this->refreshTopic()),
        ];
    }

    /**
     * Nothing to save until there is a script.
     */
    protected function getFormActions(): array
    {
        return $this->topic()->status->hasScript() ? parent::getFormActions() : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TopicIdea $record */
        try {
            return app(UpdateTopicScript::class)->execute($record, (array) ($data['script'] ?? []));
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Skripta nije spremljena')->body($e->getMessage())->danger()->send();

            $this->halt();
        }
    }

    private function refreshTopic(): void
    {
        $this->redirect(TopicIdeaResource::getUrl('edit', ['record' => $this->topic()]));
    }

    private function topic(): TopicIdea
    {
        /** @var TopicIdea $record */
        $record = $this->getRecord();

        return $record;
    }
}
