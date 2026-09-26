<?php

namespace App\Services\Alerts\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoWaChannel
{
    protected string $baseUrl;

    protected ?string $user;

    protected ?string $password;

    protected ?string $defaultRecipient;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.gowa.base_url', env('GOWA_BASE_URL', 'http://localhost:3020')), '/');
        $this->user = config('services.gowa.user', env('GOWA_USER'));
        $this->password = config('services.gowa.password', env('GOWA_PASSWORD'));
        $this->defaultRecipient = config('services.gowa.recipient_phone', env('GOWA_RECIPIENT_PHONE'));
    }

    /**
     * Send a WhatsApp message via GoWA.
     */
    public function sendMessage(string $message, ?string $phone = null): array
    {
        $targetPhone = $phone ?: $this->defaultRecipient;

        if (empty($targetPhone)) {
            return [
                'ok' => false,
                'error' => 'Destinatário WhatsApp não configurado.',
            ];
        }

        // Clean phone number (remove +, spaces, hyphens)
        $cleanPhone = preg_replace('/[^\d]/', '', $targetPhone);

        try {
            $request = Http::timeout(10);

            if ($this->user && $this->password) {
                $request = $request->withBasicAuth($this->user, $this->password);
            }

            $response = $request->post("{$this->baseUrl}/send/message", [
                'phone' => $cleanPhone,
                'message' => $message,
            ]);

            if ($response->successful()) {
                return [
                    'ok' => true,
                    'data' => $response->json(),
                ];
            }

            Log::error('GoWA send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'ok' => false,
                'error' => 'GoWA error '.$response->status().': '.$response->body(),
            ];
        } catch (\Throwable $e) {
            Log::error('GoWA connection exception: '.$e->getMessage());

            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
