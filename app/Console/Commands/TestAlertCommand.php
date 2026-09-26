<?php

namespace App\Console\Commands;

use App\Services\Alerts\Channels\GoWaChannel;
use Illuminate\Console\Command;

class TestAlertCommand extends Command
{
    protected $signature = 'pricewatch:test-alert {phone?}';
    protected $description = 'Envia um alerta de teste via GoWA para validar a integração com WhatsApp';

    public function handle(GoWaChannel $channel): int
    {
        $phone = $this->argument('phone');
        $this->info('Enviando mensagem de teste via GoWA...');

        $msg = "🚨 *PriceWatch*: Teste de conectividade com sucesso!\n"
            ."Sistema de monitoramento e alertas ativo em produção:\n"
            ."🔗 https://buscador.mgnexus.com.br\n"
            ."⏰ Horário: ".now()->format('d/m/Y H:i:s');

        $result = $channel->sendMessage($msg, $phone);

        if ($result['ok']) {
            $this->info('Mensagem enviada com sucesso ao WhatsApp!');
            $this->line(json_encode($result['data'], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->error('Falha ao enviar mensagem: '.($result['error'] ?? 'Erro desconhecido'));

        return self::FAILURE;
    }
}
