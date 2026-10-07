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
        if (! empty($configuredSecret)) {
            $incomingSecret = $request->header('X-Webhook-Secret') ?? $request->query('secret');
            if ($incomingSecret !== $configuredSecret) {
                Log::warning('Unauthorized WhatsApp webhook attempt', [
                    'ip' => $request->ip(),
                ]);

                return response()->json(['error' => 'Unauthorized'], 401);
            }
        }

        $data = $request->all();

        // Optional log for debugging incoming events
        if (config('app.debug')) {
            Log::debug('GoWA webhook received', [
                'event' => $data['event'] ?? 'unknown',
            ]);
        }

        // Dispatch background job to process message asynchronously
        ProcessWhatsAppGroupMessageJob::dispatch($data);

        return response()->json([
            'status' => 'queued',
            'message' => 'Event accepted for background processing',
        ], 200);
    }
}
