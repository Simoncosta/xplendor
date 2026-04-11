<?php

namespace App\Services\ActionExecution\Handlers;

use App\Models\Car;
use App\Services\ActionExecution\Contracts\ActionExecutionHandlerInterface;

class NotifyClientWhatsappAction implements ActionExecutionHandlerInterface
{
    public function execute(Car $car, array $context = []): array
    {
        $recipient = $this->resolveRecipient($car);

        if (!$recipient['phone']) {
            throw new \DomainException('Não existe contacto WhatsApp disponível para esta viatura.');
        }

        $message = $this->buildMessage($car, $context);
        $url = 'https://wa.me/'.$recipient['phone'].'?text='.rawurlencode($message);

        return [
            'action' => 'notify_client_whatsapp',
            'status' => 'prepared',
            'message' => 'Mensagem WhatsApp preparada com sucesso.',
            'data' => [
                'action' => 'notify_client_whatsapp',
                'status' => 'prepared',
                'recipient' => $recipient,
                'message_text' => $message,
                'whatsapp_url' => $url,
            ],
        ];
    }

    private function resolveRecipient(Car $car): array
    {
        $seller = $car->seller;
        $company = $car->company;

        $rawPhone = $seller?->whatsapp
            ?: $seller?->mobile
            ?: $company?->mobile
            ?: $company?->phone;

        return [
            'name' => $seller?->name ?: ($company?->trade_name ?: $company?->fiscal_name ?: 'Contacto do stand'),
            'phone' => $this->normalizePhone($rawPhone),
        ];
    }

    private function buildMessage(Car $car, array $context = []): string
    {
        $carName = trim(implode(' ', array_filter([
            $car->brand?->name,
            $car->model?->name,
            $car->version,
        ])));

        $decision = strtoupper((string) ($context['decision'] ?? 'MANTER'));

        return match ($decision) {
            'PARAR' => "Olá! A campanha da viatura {$carName} foi sinalizada para pausa imediata. Convém rever posicionamento, segmentação e próximos passos ainda hoje.",
            'CORRIGIR' => "Olá! A viatura {$carName} foi sinalizada para otimização. Há tráfego, mas precisamos rever a abordagem criativa e a mensagem principal.",
            'ESCALAR' => "Olá! A viatura {$carName} está a responder bem e ficou sinalizada para escalar investimento. Vale a pena validar disponibilidade e prioridade comercial.",
            default => "Olá! A viatura {$carName} está em fase de aprendizagem. Mantemos monitorização ativa e voltamos a avaliar assim que entrar mais sinal.",
        };
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (!$digits) {
            return null;
        }

        if (str_starts_with($digits, '351')) {
            return $digits;
        }

        if (strlen($digits) === 9) {
            return '351'.$digits;
        }

        return $digits;
    }
}
