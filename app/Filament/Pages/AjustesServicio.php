<?php

namespace App\Filament\Pages;

use App\Models\Ajuste;
use App\Models\ChatMensaje;
use App\Services\Chat\RespondedorMixto;
use App\Services\Menu\TokenClaude;
use BackedEnum;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Interruptores de la instancia: chat habilitado y chat con Claude, mas el estado del chat. Cada cambio se audita (Ajuste::fijar). */
class AjustesServicio extends Page
{
    protected string $view = 'filament.pages.ajustes-servicio';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|\UnitEnum|null $navigationGroup = 'IA';

    protected static ?string $navigationLabel = 'Ajustes del servicio';

    protected static ?string $title = 'Ajustes del servicio';

    protected static ?string $slug = 'ajustes-servicio';

    protected static ?int $navigationSort = 21;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'chat_habilitado' => Ajuste::chatHabilitado(),
            'chat_claude_habilitado' => Ajuste::claudeChatHabilitado(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Chat')->schema([
                Toggle::make('chat_habilitado')->label('Chat habilitado')->live()
                    ->helperText('El túnel del nodo es la frontera de seguridad: quien tenga acceso a esta instancia ya está autenticado y puede conversar y, con Claude, consultar los gastos, líneas, categorías y establecimientos (nunca bancos, cuentas, activos ni cripto). Apágalo para que el servicio rechace el chat.')
                    ->afterStateUpdated(fn (bool $state) => Ajuste::fijar(Ajuste::CHAT_HABILITADO, $state)),
                Toggle::make('chat_claude_habilitado')->label('Chat con Claude')->live()
                    ->helperText('Con esto apagado el chat responde siempre con el bot básico (sin IA) y no pide el token de Claude, aunque haya uno guardado.')
                    ->afterStateUpdated(fn (bool $state) => Ajuste::fijar(Ajuste::CHAT_CLAUDE_HABILITADO, $state)),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $claude = TokenClaude::existe();
        $bot = (string) config('chat.respondedor_bot');

        return [
            'tokenClaude' => $claude,
            'motor' => RespondedorMixto::claudeActivo() ? 'Claude' : (class_exists($bot) ? 'Bot básico (sin IA)' : 'Eco (provisional)'),
            'claudeChatHabilitado' => Ajuste::claudeChatHabilitado(),
            'mensajes' => ChatMensaje::query()->count(),
            'chatHabilitado' => Ajuste::chatHabilitado(),
        ];
    }
}
