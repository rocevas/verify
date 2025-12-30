<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BlacklistResource\Pages;
use App\Models\Blacklist;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;

class BlacklistResource extends Resource
{
    protected static ?string $model = Blacklist::class;

    protected static ?string $navigationLabel = 'Blacklist';
    
    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-shield-exclamation';
    }
    
    public static function getNavigationGroup(): ?string
    {
        return 'Settings';
    }

    protected static ?string $modelLabel = 'Blacklist Entry';

    protected static ?string $pluralModelLabel = 'Blacklist Entries';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('email')
                    ->label('Email or Domain')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('example@domain.com or domain.com')
                    ->helperText('Enter full email address or domain name'),
                Forms\Components\Select::make('type')
                    ->label('Type')
                    ->options([
                        'email' => 'Email',
                        'domain' => 'Domain',
                    ])
                    ->required()
                    ->default('email')
                    ->helperText('Email: exact match, Domain: matches all emails from this domain'),
                Forms\Components\Select::make('reason')
                    ->label('Reason')
                    ->options([
                        'spamtrap' => 'Spamtrap',
                        'abuse' => 'Abuse',
                        'do_not_mail' => 'Do Not Mail',
                        'bounce' => 'Bounce',
                        'complaint' => 'Complaint',
                        'other' => 'Other',
                    ])
                    ->required()
                    ->default('other'),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3)
                    ->maxLength(65535)
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Inactive entries will not be checked during verification'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->label('Email/Domain')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'email' => 'primary',
                        'domain' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('reason')
                    ->label('Reason')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'spamtrap' => 'danger',
                        'abuse' => 'warning',
                        'do_not_mail' => 'gray',
                        'bounce' => 'info',
                        'complaint' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('active')
                    ->label('Active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->notes),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'email' => 'Email',
                        'domain' => 'Domain',
                    ]),
                Tables\Filters\SelectFilter::make('reason')
                    ->options([
                        'spamtrap' => 'Spamtrap',
                        'abuse' => 'Abuse',
                        'do_not_mail' => 'Do Not Mail',
                        'bounce' => 'Bounce',
                        'complaint' => 'Complaint',
                        'other' => 'Other',
                    ]),
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Active')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlacklists::route('/'),
            'create' => Pages\CreateBlacklist::route('/create'),
            'edit' => Pages\EditBlacklist::route('/{record}/edit'),
        ];
    }
}

