<?php

use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])
    ->name('api.webhooks.whatsapp');
