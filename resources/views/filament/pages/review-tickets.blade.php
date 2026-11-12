<x-filament-panels::page>
    <x-filament::section>
        @if ($total === 0)
            No hay ningún gasto pendiente de revisar.
        @else
            Hay {{ $total }} gasto(s) pendiente(s) de revisar. Para repasarlos como tarjetas, escribe «revisar tickets» en el chat y abre el enlace.
        @endif
    </x-filament::section>
    @if ($filas !== [])
        <x-filament::section heading="Pendientes (más antiguos primero)">
            <ul class="space-y-2">
                @foreach ($filas as $fila)
                    <li class="flex flex-wrap justify-between gap-2">
                        <a href="{{ $fila['url'] }}" class="text-primary-600 hover:underline">{{ $fila['establecimiento'] }}</a>
                        <span>{{ $fila['fecha'] }} · {{ $fila['total'] }}</span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
