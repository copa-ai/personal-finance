<x-filament-widgets::widget>
    <x-filament::callout icon="heroicon-o-exclamation-triangle" color="warning" heading="Revisa estos datos">
        <x-slot name="description">
            <ul style="list-style: disc; padding-inline-start: 1.25rem;">
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </x-slot>
    </x-filament::callout>
</x-filament-widgets::widget>
