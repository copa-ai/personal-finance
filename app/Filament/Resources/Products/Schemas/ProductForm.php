<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Resources\Needs\Schemas\NeedForm;
use App\Filament\Resources\Categories\Schemas\CategoryForm;
use App\Models\Category;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ProductForm
{
    public static function components(bool $hideCategoryField = false, bool $hideNeedField = false): array
    {
        return [
            Section::make('Información Básica')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required(),
                    Select::make('category_id')
                        ->label('Categoría')
                        ->options(fn () => Category::query()
                            ->with(['parent.parent'])
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(function (Category $category): array {
                                $ancestors = $category->ancestor_names;
                                $suffix = $ancestors
                                    ? ' (' . implode(' > ', $ancestors) . ')'
                                    : '';

                                return [$category->id => $category->name . $suffix];
                            })
                            ->all())
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->hidden($hideCategoryField)
                        ->dehydrated(! $hideCategoryField)
                        ->relationship('category', 'name')
                        ->createOptionForm(CategoryForm::components())
                        ->editOptionForm(CategoryForm::components()),
                    Select::make('need_id')
                        ->relationship('need', 'name')
                        ->label('Necesidad')
                        ->nullable()
                        ->createOptionForm(NeedForm::components())
                        ->editOptionForm(NeedForm::components())
                        ->hidden($hideNeedField)
                        ->dehydrated(! $hideNeedField),
                    TextInput::make('brand')
                        ->label('Marca'),
                    TextInput::make('variant')
                        ->label('Variante')
                        ->afterLabel(Schema::start([
                            Icon::make(Heroicon::QuestionMarkCircle)
                                ->tooltip('Especifica la variante del producto, por ejemplo sabor, tamaño o modelo.'),
                        ])),
                    TextInput::make('unit_of_measure')
                        ->label('Unidad de Medida (Ej: kg, L, ud)')
                        ->default('Und')
                        ->required(),
                ]),
            Section::make('Métricas y Estado')
                ->columns(2)
                ->schema([
                    Toggle::make('is_consumable')
                        ->label('¿Es Consumible?')
                        ->default(true)
                        ->required(),
                    Toggle::make('active')
                        ->label('¿Activo?')
                        ->afterLabel(Schema::start([
                            Icon::make(Heroicon::QuestionMarkCircle)
                                ->tooltip('Activa o desactiva el producto. Si está inactivo, seguirá guardado para el historial, pero podrás filtrarlo y evitar usarlo en nuevos registros.'),
                        ]))
                        ->default(true)
                        ->required(),
                    TextInput::make('current_quantity')
                        ->label('Cantidad Actual')
                        ->required()
                        ->numeric()
                        ->default(0),
                    TextInput::make('daily_consumption_rate')
                        ->label('Tasa de Consumo Diario')
                        ->numeric(),
                    TextInput::make('target_price')
                        ->label('Precio Objetivo (€)')
                        ->afterLabel(Schema::start([
                            Icon::make(Heroicon::QuestionMarkCircle)
                                ->tooltip('Indica el precio por UD, KG, L...'),
                        ]))
                        ->numeric()
                        ->prefix('€'),
                    DateTimePicker::make('last_updated_at')
                        ->label('Última Actualización')
                        ->default(now())
                        ->required(),
                ]),
            Section::make('Comentarios')
                ->schema([
                    Textarea::make('notes')
                        ->label('Notas')
                        ->columnSpanFull(),
                ]),
        ];
    }

    public static function configure(
        Schema $schema,
        bool $hideCategoryField = false,
        bool $hideNeedField = false
    ): Schema {
        return $schema
            ->components(self::components($hideCategoryField, $hideNeedField));
    }
}
