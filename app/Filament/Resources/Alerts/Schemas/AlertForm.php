<?php

namespace App\Filament\Resources\Alerts\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AlertForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('status')
                    ->label('Status')
                    ->disabled(),

                TextInput::make('sent_at')
                    ->label('Data de Envio')
                    ->disabled(),

                Textarea::make('error_message')
                    ->label('Erro')
                    ->columnSpanFull()
                    ->disabled(),

                KeyValue::make('payload')
                    ->label('Payload do Alerta')
                    ->columnSpanFull()
                    ->disabled(),
            ]);
    }
}
