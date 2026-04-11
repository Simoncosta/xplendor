<?php

namespace App\Services\ActionExecution;

use App\Models\Car;
use App\Services\ActionExecution\Contracts\ActionExecutionHandlerInterface;
use App\Services\ActionExecution\Handlers\GenerateNewCopyAction;
use App\Services\ActionExecution\Handlers\NotifyClientWhatsappAction;
use App\Services\ActionExecution\Handlers\PauseCampaignAction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ActionExecutionService
{
    private const ACTIONS = [
        'pause_campaign' => [
            'label' => 'Pausar campanha',
            'implemented' => true,
            'handler' => PauseCampaignAction::class,
        ],
        'notify_client_whatsapp' => [
            'label' => 'Avisar cliente via WhatsApp',
            'implemented' => true,
            'handler' => NotifyClientWhatsappAction::class,
        ],
        'generate_new_copy' => [
            'label' => 'Gerar nova copy',
            'implemented' => true,
            'handler' => GenerateNewCopyAction::class,
        ],
        'suggest_new_vehicle' => [
            'label' => 'Sugerir nova viatura',
            'implemented' => false,
        ],
        'launch_template_campaign' => [
            'label' => 'Lançar nova campanha com template',
            'implemented' => false,
        ],
        'duplicate_winning_campaign' => [
            'label' => 'Duplicar campanha vencedora',
            'implemented' => false,
        ],
        'swap_creative' => [
            'label' => 'Trocar criativo',
            'implemented' => false,
        ],
        'mark_lead_low_quality' => [
            'label' => 'Marcar lead como baixa qualidade',
            'implemented' => false,
        ],
    ];

    public function execute(Car $car, string $action, array $context = []): array
    {
        $definition = self::ACTIONS[$action] ?? null;

        if (!$definition) {
            throw new \DomainException('Ação inválida ou não suportada.');
        }

        if (!($definition['implemented'] ?? false)) {
            $result = [
                'action' => $action,
                'status' => 'stub',
                'message' => 'Ação preparada para expansão futura, ainda não disponível neste MVP.',
                'data' => [
                    'action' => $action,
                    'status' => 'stub',
                ],
            ];

            $this->logExecution($car, $action, $context, $result);

            return $result;
        }

        /** @var ActionExecutionHandlerInterface $handler */
        $handler = app($definition['handler']);
        $result = $handler->execute($car, $context);

        $this->logExecution($car, $action, $context, $result);

        return $result;
    }

    private function logExecution(Car $car, string $action, array $context, array $result): void
    {
        Log::info('[ActionExecution] Action handled', [
            'company_id' => $car->company_id,
            'car_id' => $car->id,
            'action' => $action,
            'context' => $context,
            'status' => $result['status'] ?? null,
            'actor_user_id' => Auth::id(),
        ]);
    }
}
