<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Services\Acciones\ResumenGastos;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Solo lectura: los gastos pendientes de revisar, en el mismo orden que el mazo del chat (más antiguos primero). */
class ReviewTickets extends Page
{
    protected string $view = 'filament.pages.review-tickets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'IA';

    protected static ?string $navigationLabel = 'Revisar tickets';

    protected static ?string $title = 'Revisar tickets';

    protected static ?string $slug = 'revisar-tickets';

    protected static ?int $navigationSort = 10;

    public function getViewData(): array
    {
        $gastos = Expense::query()->with('establishment')->where('pending_review', true)
            ->orderByRaw('date asc nulls last')->orderBy('id')->limit(100)->get();

        return [
            'total' => Expense::query()->where('pending_review', true)->count(),
            'filas' => $gastos->map(fn (Expense $g): array => [
                'fecha' => $g->date?->copy()->setTimezone(ResumenGastos::zona())->format('d/m/Y H:i') ?? 'Sin fecha',
                'establecimiento' => $g->establishment?->name ?? 'Sin establecimiento',
                'total' => $g->total === null ? 'Sin total' : Expense::formatMoney($g->total),
                'url' => ExpenseResource::getUrl('view', ['record' => $g]),
            ])->all(),
        ];
    }
}
