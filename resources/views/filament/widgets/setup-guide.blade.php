<x-filament-widgets::widget>
    <x-filament::section
        heading="Kako početi"
        :description="$doneCount.' od '.count($steps).' koraka gotovo. Kad prođeš sva četiri, ovaj vodič nestaje.'"
        icon="heroicon-o-rocket-launch"
    >
        <ol class="hub-steps">
            @foreach ($steps as $index => $step)
                <li @class(['hub-step', 'hub-step--done' => $step['done'], 'hub-step--next' => $index === $next])>
                    <span class="hub-step__marker" aria-hidden="true">
                        @if ($step['done'])
                            <x-filament::icon icon="heroicon-m-check" class="hub-step__check" />
                        @else
                            {{ $index + 1 }}
                        @endif
                    </span>
                    <span class="hub-step__body">
                        <span class="hub-step__label">{{ $step['label'] }}</span>
                        <span class="hub-step__hint">{{ $step['hint'] }}</span>
                    </span>
                    @unless ($step['done'])
                        <x-filament::button
                            tag="a"
                            :href="$step['url']"
                            size="sm"
                            :color="$index === $next ? 'primary' : 'gray'"
                        >
                            {{ $index === $next ? 'Nastavi' : 'Otvori' }}
                        </x-filament::button>
                    @endunless
                </li>
            @endforeach
        </ol>
    </x-filament::section>
</x-filament-widgets::widget>
