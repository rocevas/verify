<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BlocklistMonitorResource\Pages;
use App\Models\BlocklistMonitor;
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

class BlocklistMonitorResource extends Resource
{
    protected static ?string $model = BlocklistMonitor::class;

    protected static ?string $navigationLabel = 'Blocklist';
    
    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-shield-exclamation';
    }
    
    public static function getNavigationGroup(): ?string
    {
        return 'Monitors';
    }

    protected static ?string $modelLabel = 'Blocklist Monitor';

    protected static ?string $pluralModelLabel = 'Blocklist Monitors';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('Monitor Name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('My Domain Monitor')
                    ->helperText('A descriptive name for this monitor'),
                Forms\Components\Select::make('type')
                    ->label('Type')
                    ->options([
                        'domain' => 'Domain',
                        'ip' => 'IP Address',
                    ])
                    ->required()
                    ->default('domain')
                    ->live()
                    ->helperText('Select whether to monitor a domain or IP address'),
                Forms\Components\TextInput::make('target')
                    ->label('Domain or IP Address')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('example.com or 192.168.1.1')
                    ->helperText(fn ($get) => $get('type') === 'domain' 
                        ? 'Enter the domain name to monitor (e.g., example.com)' 
                        : 'Enter the IP address to monitor (e.g., 192.168.1.1)')
                    ->rules(function ($get) {
                        $type = $get('type');
                        if ($type === 'ip') {
                            return ['ip'];
                        }
                        return ['regex:/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/i', 'max:255'];
                    }),
                Forms\Components\Toggle::make('active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Inactive monitors will not be checked'),
                Forms\Components\TextInput::make('check_interval_minutes')
                    ->label('Check Interval (minutes)')
                    ->numeric()
                    ->default(60)
                    ->minValue(5)
                    ->maxValue(1440)
                    ->required()
                    ->helperText('How often to check this monitor (minimum 5 minutes)'),
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
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'domain' => 'primary',
                        'ip' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('target')
                    ->label('Target')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_blocklisted')
                    ->label('Blocklisted')
                    ->boolean()
                    ->trueIcon('heroicon-o-shield-exclamation')
                    ->trueColor('danger')
                    ->falseIcon('heroicon-o-check-circle')
                    ->falseColor('success'),
                Tables\Columns\TextColumn::make('blocklists')
                    ->label('Found In')
                    ->formatStateUsing(function ($record) {
                        if (!$record->is_blocklisted) {
                            return 'None';
                        }
                        
                        $details = $record->last_check_details;
                        if (is_array($details) && isset($details['blocklists'])) {
                            return implode(', ', $details['blocklists']);
                        }
                        
                        return 'Unknown';
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
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'domain' => 'Domain',
                        'ip' => 'IP Address',
                    ]),
                Tables\Filters\TernaryFilter::make('is_blocklisted')
                    ->label('Blocklisted')
                    ->placeholder('All')
                    ->trueLabel('Blocklisted only')
                    ->falseLabel('Not blocklisted only'),
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
                    ->action(function (BlocklistMonitor $record) {
                        \App\Jobs\CheckBlocklistMonitorJob::dispatch($record->id);
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
            'index' => Pages\ListBlocklistMonitors::route('/'),
            'create' => Pages\CreateBlocklistMonitor::route('/create'),
            'edit' => Pages\EditBlocklistMonitor::route('/{record}/edit'),
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

