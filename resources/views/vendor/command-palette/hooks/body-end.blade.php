@php
    $userAgent = request()->header('User-Agent', '');
    $isMobile = (bool) preg_match('/Mobile|Android|iP(hone|od|ad)|IEMobile|BlackBerry|Opera Mini/i', $userAgent);
@endphp

@unless ($isMobile)
    @livewire(\Usamamuneerchaudhary\CommandPalette\Livewire\CommandPalette::class, ['panelId' => $panel?->getId()])
@endunless
