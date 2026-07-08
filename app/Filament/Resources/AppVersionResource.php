<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppVersionResource\Pages;
use App\Models\AppVersion;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use BackedEnum;
use Illuminate\Support\Facades\Auth;

class AppVersionResource extends Resource
{
    protected static ?string $model = AppVersion::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Versiones App';

    protected static string | \UnitEnum | null $navigationGroup = 'Configuración';

    protected static ?string $modelLabel = 'Versión';

    protected static ?string $pluralModelLabel = 'Versiones de la App';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Información de la Versión')
                    ->schema([
                        Forms\Components\TextInput::make('version')
                            ->required()
                            ->placeholder('0.5.3')
                            ->maxLength(20)
                            ->unique(ignoreRecord: true)
                            ->label('Versión')
                            ->helperText('Formato semver: MAJOR.MINOR.PATCH (ej: 0.5.3)'),

                        Forms\Components\TextInput::make('min_version')
                            ->required()
                            ->default('0.1.0')
                            ->maxLength(20)
                            ->label('Versión Mínima Soportada')
                            ->helperText('Versiones anteriores a esta serán obligadas a actualizar'),

                        Forms\Components\Toggle::make('force_update')
                            ->label('Forzar Actualización')
                            ->helperText('Si se activa, el usuario NO podrá usar la app sin actualizar')
                            ->default(false),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Versión Activa')
                            ->helperText('Solo puede haber UNA versión activa. Al activar esta, se desactivarán las demás.')
                            ->default(false),
                    ])
                    ->columns(2),

                Section::make('Archivo APK')
                    ->schema([
                        Forms\Components\FileUpload::make('apk_path')
                            ->required()
                            ->disk('public')
                            ->directory('apks')
                            ->acceptedFileTypes([
                                'application/vnd.android.package-archive',
                                'application/octet-stream',
                                'application/zip',
                            ])
                            ->maxSize(150 * 1024) // 150MB
                            ->label('Archivo APK')
                            ->helperText('Sube el archivo .apk compilado (máx. 150MB)'),
                    ]),

                Section::make('Notas del Release')
                    ->schema([
                        Forms\Components\Textarea::make('release_notes')
                            ->rows(4)
                            ->label('Notas de la versión')
                            ->placeholder('Describe los cambios de esta versión...'),
                    ]),

                Forms\Components\Hidden::make('created_by')
                    ->default(fn () => Auth::id()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('version')
                    ->label('Versión')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->formatStateUsing(fn (string $state) => "v{$state}"),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\IconColumn::make('force_update')
                    ->label('Forzada')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('min_version')
                    ->label('Mín. Soportada')
                    ->formatStateUsing(fn (string $state) => "v{$state}"),

                Tables\Columns\TextColumn::make('release_notes')
                    ->label('Notas')
                    ->limit(50)
                    ->wrap(),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Subido por')
                    ->default('Sistema'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todas')
                    ->trueLabel('Solo activas')
                    ->falseLabel('Solo inactivas'),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\Action::make('activate')
                    ->label('Activar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('¿Activar esta versión?')
                    ->modalDescription('Esta versión será la que se ofrezca a los usuarios. Las demás se desactivarán.')
                    ->action(fn (AppVersion $record) => $record->activate())
                    ->hidden(fn (AppVersion $record) => $record->is_active),
                Actions\DeleteAction::make()
                    ->hidden(fn (AppVersion $record) => $record->is_active),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAppVersions::route('/'),
            'create' => Pages\CreateAppVersion::route('/create'),
            'edit'   => Pages\EditAppVersion::route('/{record}/edit'),
        ];
    }
}
