<?php

namespace App\Filament\Resources\CollectionRuns\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CollectionRunForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('status')
                    ->label('Status')
                    ->disabled(),

                TextInput::make('http_status')
                    ->label('Status HTTP')
                    ->disabled(),

                TextInput::make('duration_ms')
                    ->label('Duração (ms)')
                    ->disabled(),

                TextInput::make('error_code')
                    ->label('Código de Erro')
                    ->disabled(),

                Textarea::make('error_message')
                    ->label('Mensagem de Erro')
                    ->columnSpanFull()
                    ->rows(4)
                    ->disabled(),
            ]);
    }
}
