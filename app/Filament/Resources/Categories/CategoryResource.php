<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Models\Category;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $modelLabel = 'категорія';

    protected static ?string $pluralModelLabel = 'Категорії витрат';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Назва')->required()->maxLength(100)->unique(ignoreRecord: true),
            Toggle::make('is_active')->label('Активна')->default(true)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Назва')->searchable()->sortable(),
                IconColumn::make('is_active')->label('Активна')->boolean(),
                TextColumn::make('transactions_count')->counts('transactions')->label('Операцій')
                    ->description(fn (Category $record): ?string => $record->transactions_count > 0
                        ? 'Використовується. Замість видалення деактивуйте категорію.' : null),
            ])
            ->filters([TernaryFilter::make('is_active')->label('Активність')->trueLabel('Активні')->falseLabel('Неактивні')])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->authorize(fn (Category $record): bool => Gate::allows('update', $record))
                    ->before(fn (Category $record) => Gate::authorize('update', $record)),
                Action::make('deactivate')->label('Деактивувати')->requiresConfirmation()
                    ->visible(fn (Category $record): bool => $record->is_active)
                    ->authorize(fn (Category $record): bool => Gate::allows('update', $record))
                    ->action(function (Category $record): void {
                        Gate::authorize('update', $record);
                        $record->update(['is_active' => false]);
                    }),
                Action::make('activate')->label('Активувати')
                    ->visible(fn (Category $record): bool => ! $record->is_active)
                    ->authorize(fn (Category $record): bool => Gate::allows('update', $record))
                    ->action(function (Category $record): void {
                        Gate::authorize('update', $record);
                        $record->update(['is_active' => true]);
                    }),
                DeleteAction::make()->authorize(fn (Category $record) => Gate::inspect('delete', $record))
                    ->authorizationTooltip()
                    ->before(fn (Category $record) => Gate::authorize('delete', $record)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCategories::route('/')];
    }
}
