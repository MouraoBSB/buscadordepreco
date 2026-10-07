<?php

namespace App\Console\Commands;

use App\Services\Coupons\WhatsAppCouponExtractorService;
use Illuminate\Console\Command;

class SimulateWhatsAppMessageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pricewatch:simulate-whatsapp-coupon 
                            {text : O texto da mensagem recebida no grupo} 
                            {--group=Achados e Cupons : Nome do grupo de ofertas}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simula uma mensagem recebida de grupo de WhatsApp para testar a extração de cupom';

    /**
     * Execute the console command.
     */
    public function handle(WhatsAppCouponExtractorService $extractor): int
    {
        $text = $this->argument('text');
        $group = $this->option('group');

        $this->info("Processando mensagem do grupo '{$group}'...");
        $this->line("Mensagem: \"{$text}\"");

        $coupon = $extractor->extractAndStore($text, [
            'group_name' => $group,
            'is_group' => true,
            'simulated' => true,
        ]);

        if (! $coupon) {
            $this->warn('Nenhum cupom válido identificado no texto fornecido.');

            return self::FAILURE;
        }

        $this->info('✅ Cupom extraído e salvo com sucesso!');
        $this->table(
            ['ID', 'Código', 'Loja', 'Produto', 'Desconto', 'Origem', 'Status'],
            [[
                $coupon->id,
                $coupon->code,
                $coupon->store?->name ?? 'Geral / Todas',
                $coupon->product?->commercial_name ?? 'Geral / Todos',
                $coupon->discount_type === 'percentage' ? "{$coupon->discount_value}%" : "R$ {$coupon->discount_value}",
                $coupon->source_type,
                $coupon->active ? 'Ativo' : 'Inativo',
            ]]
        );

        return self::SUCCESS;
    }
}
