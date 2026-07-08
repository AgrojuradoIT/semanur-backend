<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehiculoResource\Pages;
use App\Models\Vehiculo;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions;
use Filament\Schemas\Schema;
use BackedEnum;

use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Storage;
use App\Filament\Resources\VehiculoResource\RelationManagers\DocumentosRelationManager;

class VehiculoResource extends Resource
{
    protected static ?string $model = Vehiculo::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-truck';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('placa')
                    ->required()
                    ->maxLength(20),
                Forms\Components\TextInput::make('tipo')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('marca')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('modelo')
                    ->required()
                    ->maxLength(255),
                FileUpload::make('imagen_url')
                    ->label('Foto del vehículo')
                    ->image()
                    ->disk('public')
                    ->directory('vehiculos')
                    ->maxSize(5120)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->saveUploadedFileUsing(function ($file, $record) {
                        $uuid = \Illuminate\Support\Str::uuid()->toString();
                        
                        $manager = \Intervention\Image\ImageManager::usingDriver(
                            \Intervention\Image\Drivers\Gd\Driver::class
                        );
                        
                        $tempPath = $file->getRealPath();
                        
                        // Imagen principal (max 1200px, WebP 80%)
                        $mainImg = $manager->decodePath($tempPath);
                        $mainImg->scale(width: 1200);
                        $mainEncoded = $mainImg->encodeUsingFormat(\Intervention\Image\Format::WEBP, quality: 80);
                        $mainPath = "vehiculos/{$uuid}.webp";
                        Storage::disk('public')->put($mainPath, $mainEncoded->toString());
                        
                        // Thumbnail (200x200, WebP 80%)
                        $thumbImg = $manager->decodePath($tempPath);
                        $thumbImg->cover(width: 200, height: 200);
                        $thumbEncoded = $thumbImg->encodeUsingFormat(\Intervention\Image\Format::WEBP, quality: 80);
                        $thumbPath = "vehiculos/thumbs/{$uuid}.webp";
                        Storage::disk('public')->put($thumbPath, $thumbEncoded->toString());
                        
                        if ($record) {
                            $old = $record->imagen_url;
                            $oldThumb = $record->imagen_thumb_url;
                            $record->imagen_thumb_url = $thumbPath;
                            $record->save();
                            if ($old) Storage::disk('public')->delete($old);
                            if ($oldThumb) Storage::disk('public')->delete($oldThumb);
                        }
                        
                        return $mainPath;
                    })
                    ->deleteUploadedFileUsing(function ($record) {
                        if ($record) {
                            if ($record->imagen_url) Storage::disk('public')->delete($record->imagen_url);
                            if ($record->imagen_thumb_url) Storage::disk('public')->delete($record->imagen_thumb_url);
                        }
                    })
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('horometro_actual')
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('kilometraje_actual')
                    ->numeric()
                    ->default(0),
                Forms\Components\DatePicker::make('fecha_vencimiento_soat')
                    ->label('Vencimiento SOAT (Automático)')
                    ->disabled()
                    ->helperText('Se actualiza automáticamente desde la pestaña de documentos de abajo.'),
                Forms\Components\DatePicker::make('fecha_vencimiento_tecnomecanica')
                    ->label('Vencimiento Tecnomecánica (Automático)')
                    ->disabled()
                    ->helperText('Se actualiza automáticamente desde la pestaña de documentos de abajo.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('imagen_url')
                    ->label('Foto')
                    ->circular()
                    ->size(40)
                    ->disk('public'),
                Tables\Columns\TextColumn::make('placa')
                    ->searchable(),
                Tables\Columns\TextColumn::make('tipo')
                    ->searchable(),
                Tables\Columns\TextColumn::make('marca')
                    ->searchable(),
                Tables\Columns\TextColumn::make('modelo')
                    ->searchable(),
                Tables\Columns\TextColumn::make('horometro_actual')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            DocumentosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehiculos::route('/'),
            'create' => Pages\CreateVehiculo::route('/create'),
            'edit' => Pages\EditVehiculo::route('/{record}/edit'),
        ];
    }
}
