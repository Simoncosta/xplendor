<?php

namespace App\Services\ActionExecution\Handlers;

use App\Models\Car;
use App\Services\ActionExecution\Contracts\ActionExecutionHandlerInterface;

class GenerateNewCopyAction implements ActionExecutionHandlerInterface
{
    public function execute(Car $car, array $context = []): array
    {
        $decision = strtoupper((string) ($context['decision'] ?? 'CORRIGIR'));
        $vehicleType = $car->vehicle_type === 'motorhome' ? 'motorhome' : 'car';
        $carName = trim(implode(' ', array_filter([
            $car->brand?->name,
            $car->model?->name,
            $car->version,
        ])));

        $copy = $vehicleType === 'motorhome'
            ? $this->buildMotorhomeCopy($carName, $decision)
            : $this->buildCarCopy($carName, $decision);

        return [
            'action' => 'generate_new_copy',
            'status' => 'prepared',
            'message' => 'Nova copy sugerida gerada com sucesso.',
            'data' => [
                'action' => 'generate_new_copy',
                'status' => 'prepared',
                'copy' => $copy,
            ],
        ];
    }

    private function buildCarCopy(string $carName, string $decision): array
    {
        return match ($decision) {
            'ESCALAR' => [
                'headline' => "{$carName}: o anúncio que já está a ganhar tração",
                'primary_text' => "Esta viatura já está a gerar resposta real. É o momento certo para dar mais escala ao que já está a funcionar.",
                'cta' => 'Agendar contacto',
                'angle' => 'Tração comprovada e urgência comercial',
            ],
            'MANTER' => [
                'headline' => "{$carName}: ainda em aprendizagem, já com sinais positivos",
                'primary_text' => "O interesse já começou a aparecer. Mantemos consistência para transformar estes primeiros sinais em contacto real.",
                'cta' => 'Saber mais',
                'angle' => 'Aprendizagem com sinal inicial',
            ],
            'PARAR' => [
                'headline' => "{$carName}: precisamos de uma abordagem nova",
                'primary_text' => "A mensagem atual não está a gerar a resposta certa. Faz sentido repensar criativo, oferta e posicionamento antes de investir mais.",
                'cta' => 'Ver proposta',
                'angle' => 'Reposicionamento do anúncio',
            ],
            default => [
                'headline' => "{$carName}: mais clareza, menos fricção",
                'primary_text' => "Há interesse, mas a campanha ainda não está a converter como devia. A nova copy deve simplificar valor, prova e próxima ação.",
                'cta' => 'Falar connosco',
                'angle' => 'Correção de mensagem',
            ],
        };
    }

    private function buildMotorhomeCopy(string $carName, string $decision): array
    {
        return match ($decision) {
            'ESCALAR' => [
                'headline' => "{$carName}: liberdade com procura qualificada",
                'primary_text' => "Quando o interesse já é profundo, vale a pena escalar com uma mensagem que reforça experiência, autonomia e confiança na escolha.",
                'cta' => 'Agendar visita',
                'angle' => 'Storytelling aspiracional com prova',
            ],
            'MANTER' => [
                'headline' => "{$carName}: uma decisão que pede contexto, não pressa",
                'primary_text' => "Quem procura um motorhome quer perceber habitabilidade, layout e uso real. Mantemos a campanha enquanto aprofundamos essa narrativa.",
                'cta' => 'Explorar detalhes',
                'angle' => 'Venda consultiva e decisão longa',
            ],
            'PARAR' => [
                'headline' => "{$carName}: a campanha precisa de uma nova história",
                'primary_text' => "Antes de investir mais, faz sentido reconstruir a comunicação com foco em visual, uso real e diferenciação do interior.",
                'cta' => 'Ver nova abordagem',
                'angle' => 'Reposicionamento consultivo',
            ],
            default => [
                'headline' => "{$carName}: mostrar melhor para vender melhor",
                'primary_text' => "O interesse existe, mas a copy precisa de explicar melhor layout, habitabilidade e experiência de viagem para aproximar o contacto.",
                'cta' => 'Pedir informação',
                'angle' => 'Contexto de decisão e prova visual',
            ],
        };
    }
}
