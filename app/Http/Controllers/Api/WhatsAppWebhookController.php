<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppGroupMessageJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * Handle incoming webhooks from GoWA.
     */
    public function handle(Request $request): JsonResponse
    {
        $configuredSecret = config('services.gowa.webhook_secret');
        if (! empty($configuredSecret) && ! $this->isAuthorized($request, $configuredSecret)) {
            try {
                Log::warning('Unauthorized WhatsApp webhook attempt', [
                    'ip' => $request->ip(),
                ]);
            } catch (\Throwable) {
                // Falha no driver de log não pode derrubar o webhook
            }

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->all();

        // Descarta logo eventos que não são mensagem (presença, status, recibos)
        $event = $data['event'] ?? null;
        if ($event && $event !== 'message') {
            return response()->json([
                'status' => 'ignored',
                'message' => 'Non-message event discarded',
            ], 200);
        }

        // Log opcional para depurar eventos recebidos
        if (config('app.debug')) {
            try {
                Log::debug('GoWA webhook received', [
                    'event' => $event ?? 'unknown',
                ]);
            } catch (\Throwable) {
            }
        }

        // Enfileira o job que processa a mensagem em segundo plano
        ProcessWhatsAppGroupMessageJob::dispatch($data);

        return response()->json([
            'status' => 'queued',
            'message' => 'Event accepted for background processing',
        ], 200);
    }

    /**
     * Valida a autenticidade da requisição do webhook.
     */
    protected function isAuthorized(Request $request, string $configuredSecret): bool
    {
        // 1. Secret direto no header ou na query string
        $incomingSecret = $request->header('X-Webhook-Secret') ?? $request->query('secret');
        if ($incomingSecret && hash_equals($configuredSecret, $incomingSecret)) {
            return true;
        }

        // 2. Assinatura HMAC-SHA256 padrão do GoWA (X-Hub-Signature-256)
        $signatureHeader = $request->header('X-Hub-Signature-256');
        if ($signatureHeader && str_starts_with($signatureHeader, 'sha256=')) {
            $expectedSignature = 'sha256='.hash_hmac('sha256', $request->getContent(), $configuredSecret);
            if (hash_equals($expectedSignature, $signatureHeader)) {
                return true;
            }
        }

        return false;
    }
}
