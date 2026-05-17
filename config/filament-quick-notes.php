<?php

use Filament\View\PanelsRenderHook;
use Rarq\FilamentQuickNotes\Enums\DeletionType;
use Rarq\FilamentQuickNotes\Models\FilamentQuickNote;

return [
    /*
    |--------------------------------------------------------------------------
    | Render hook position
    |--------------------------------------------------------------------------
    |
    | Determines where the Quick Notes button appears inside the Filament panel.
    | Any value from \Filament\View\PanelsRenderHook is valid.
    |
    | Common options:
    |   PanelsRenderHook::GLOBAL_SEARCH_BEFORE  (default — left of the search bar)
    |   PanelsRenderHook::GLOBAL_SEARCH_AFTER
    |   PanelsRenderHook::TOPBAR_START
    |   PanelsRenderHook::TOPBAR_END
    |
    */
    'position' => PanelsRenderHook::GLOBAL_SEARCH_BEFORE,

    /*
    |--------------------------------------------------------------------------
    | Database table name
    |--------------------------------------------------------------------------
    |
    | Override this if the default table name conflicts with your existing schema.
    |
    */
    'table_name' => 'filament_quick_notes',

    /*
    |--------------------------------------------------------------------------
    | Quick Notes model
    |--------------------------------------------------------------------------
    |
    | You may swap in your own model as long as it extends FilamentQuickNote
    | or implements the same interface / fillable attributes.
    |
    */
    'quick_notes_model' => FilamentQuickNote::class,

    /*
    |--------------------------------------------------------------------------
    | Note deletion method
    |--------------------------------------------------------------------------
    | Determines how notes are deleted when the user clicks "Delete".
    | Options:
    |   - DeletionType::SOFT (default) — notes are soft-deleted and can be restored later.
    |   - DeletionType::FORCE — notes are permanently deleted immediately.
    |
    */
    'deletion_type' => DeletionType::SOFT,
];