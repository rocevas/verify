<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DmarcMonitorResource\Pages;
use App\Models\DmarcMonitor;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Support\Facades\Auth;

class DmarcMonitorResource extends Resource
{
    protected static ?string $model = DmarcMonitor::class;

    protected static ?string $navigationLabel = 'DMARC';
    
    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-envelope';
    }
    
    public static function getNavigationGroup(): ?string
    {
        return 'Monitors';
    }

    protected static ?string $modelLabel = 'DMARC Monitor';

    protected static ?string $pluralModelLabel = 'DMARC Monitors';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('Monitor Name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('My DMARC Monitor')
                    ->helperText('A descriptive name for this monitor'),
                Forms\Components\TextInput::make('domain')
                    ->label('Domain')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('example.com')
                    ->helperText('Enter the domain name to monitor for DMARC')
                    ->rules(['regex:/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/i', 'max:255']),
                Forms\Components\TextInput::make('report_email')
                    ->label('Report Email')
                    ->email()
                    ->maxLength(255)
                    ->placeholder('reports@example.com')
                    ->helperText('Optional: Email address to receive DMARC reports'),
                Forms\Components\Toggle::make('active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Inactive monitors will not be checked'),
                Forms\Components\TextInput::make('check_interval_minutes')
                    ->label('Check Interval (minutes)')
                    ->numeric()
                    ->default(1440)
                    ->minValue(60)
                    ->maxValue(10080)
                    ->required()
                    ->helperText('How often to check this monitor (default: 24 hours)'),
                Forms\Components\Hidden::make('user_id')
                    ->default(fn () => Auth::id()),
                Forms\Components\Select::make('team_id')
                    ->label('Team')
                    ->relationship('team', 'name')
                    ->nullable()
                    ->helperText('Optional: Associate this monitor with a team'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('domain')
                    ->label('Domain')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('has_issue')
                    ->label('Has Issue')
                    ->boolean()
                    ->trueIcon('heroicon-o-shield-exclamation')
                    ->trueColor('danger')
                    ->falseIcon('heroicon-o-check-circle')
                    ->falseColor('success'),
                Tables\Columns\TextColumn::make('issue_message')
                    ->label('Issue')
                    ->formatStateUsing(function ($record) {
                        if (!$record->has_issue) {
                            return 'OK';
                        }
                        
                        $details = $record->last_check_details;
                        if (is_array($details) && isset($details['message'])) {
                            return $details['message'];
                        }
                        
                        return 'Issue detected';
                    })
                    ->limit(50),
                Tables\Columns\IconColumn::make('active')
                    ->label('Active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('last_checked_at')
                    ->label('Last Checked')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->placeholder('Never'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('has_issue')
                    ->label('Has Issue')
                    ->placeholder('All')
                    ->trueLabel('With issues only')
                    ->falseLabel('Without issues only'),
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Active')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
                Tables\Actions\Action::make('check_now')
                    ->label('Check Now')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->action(function (DmarcMonitor $record) {
                        \App\Jobs\CheckDmarcMonitorJob::dispatch($record->id);
                        \Filament\Notifications\Notification::make()
                            ->title('Check queued')
                            ->body('The monitor check has been queued and will run shortly.')
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDmarcMonitors::route('/'),
            'create' => Pages\CreateDmarcMonitor::route('/create'),
            'edit' => Pages\EditDmarcMonitor::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        
        $user = Auth::user();
        if ($user) {
            $query->where('user_id', $user->id);
        }
        
        return $query;
    }
}

