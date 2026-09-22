<?php

namespace App\Filament\Resources\CryptoAccounts;

use App\Filament\Resources\Assets\RelationManagers\ValueSnapshotsRelationManager;
use App\Filament\Resources\CryptoAccounts\Pages\CreateCryptoAccount;
use App\Filament\Resources\CryptoAccounts\Pages\EditCryptoAccount;
use App\Filament\Resources\CryptoAccounts\Pages\ListCryptoAccounts;
use App\Filament\Resources\CryptoAccounts\Pages\ViewCryptoAccount;
use App\Filament\Resources\CryptoAccounts\Schemas\CryptoAccountForm;
use App\Filament\Resources\CryptoAccounts\Schemas\CryptoAccountInfolist;
use App\Filament\Resources\CryptoAccounts\Tables\CryptoAccountsTable;
use App\Models\CryptoAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class CryptoAccountResource extends Resource
{
    protected static ?string $model = CryptoAccount::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?string $modelLabel = 'Cuenta cripto';

    protected static ?string $pluralModelLabel = 'Cuentas cripto';

    public static function form(Schema $schema): Schema
    {
        return CryptoAccountForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CryptoAccountInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CryptoAccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ValueSnapshotsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCryptoAccounts::route('/'),
            'create' => CreateCryptoAccount::route('/create'),
            'view' => ViewCryptoAccount::route('/{record}'),
            'edit' => EditCryptoAccount::route('/{record}/edit'),
        ];
    }
}
