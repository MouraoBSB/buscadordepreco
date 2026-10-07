<?php

namespace App\Jobs;

use App\Services\Coupons\WhatsAppCouponExtractorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppGroupMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $data
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WhatsAppCouponExtractorService $extractor): void
    {
        $payload = $this->data['payload'] ?? $this->data;

        // Skip if message was sent by ourselves to prevent loops
        if (! empty($payload['is_from_me']) || ! empty($payload['from_me'])) {
            return;
        }

        // Extract message text from various GoWA structures
        $text = $this->extractText($payload);

        if (empty($text) || mb_strlen(trim($text)) < 8) {
            return;
        }

        $groupJid = $payload['from'] ?? $payload['chat_jid'] ?? $payload['remote_jid'] ?? null;
        $isGroup = ! empty($payload['is_group']) || (is_string($groupJid) && str_contains($groupJid, '@g.us'));

        // Collect metadata
        $metadata = [
            'is_group' => $isGroup,
            'group_jid' => $groupJid,
            'group_name' => $payload['group_name'] ?? $payload['chat_name'] ?? null,
            'sender' => $payload['sender'] ?? $payload['participant'] ?? null,
            'push_name' => $payload['push_name'] ?? null,
            'message_id' => $payload['id'] ?? null,
            'timestamp' => $payload['timestamp'] ?? now()->toIso8601String(),
        ];

        try {
            $extractor->extractAndStore($text, $metadata);
        } catch (\Throwable $e) {
            Log::error('Error processing WhatsApp group message: '.$e->getMessage(), [
                'metadata' => $metadata,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Extract plain text from GoWA message payload.
     */
    protected function extractText(array $payload): ?string
    {
        // 1. Direct string
        if (isset($payload['message']) && is_string($payload['message'])) {
            return $payload['message'];
        }

        if (isset($payload['body']) && is_string($payload['body'])) {
            return $payload['body'];
        }

        if (isset($payload['text']) && is_string($payload['text'])) {
            return $payload['text'];
        }

        // 2. GoWA structured message object
        if (isset($payload['message']) && is_array($payload['message'])) {
            $msg = $payload['message'];

            // Plain conversation
            if (! empty($msg['conversation'])) {
                return $msg['conversation'];
            }

            // Extended text (with preview link / formatting)
            if (! empty($msg['extended_text_message']['text'])) {
                return $msg['extended_text_message']['text'];
            }

            // Image / video with caption
            if (! empty($msg['image_message']['caption'])) {
                return $msg['image_message']['caption'];
            }

            if (! empty($msg['video_message']['caption'])) {
                return $msg['video_message']['caption'];
            }
        }

        return null;
    }
}
