<?php

namespace Tests\Unit;

use App\Models\Car;
use App\Services\ContactProbabilityService;
use Tests\TestCase;

class ContactProbabilityServiceTest extends TestCase
{
    public function test_it_calculates_high_contact_probability_from_intent_and_contact_signals(): void
    {
        $service = new ContactProbabilityService();
        $car = new Car(['vehicle_type' => 'car']);

        $result = $service->calculate(
            $car,
            ['metrics' => [
                'sessions' => 12,
                'form_opens' => 2,
                'clicks' => 20,
                'avg_time_on_page' => 80,
                'scroll' => 70,
                'leads' => 1,
                'whatsapp_clicks' => 2,
            ]],
            [
                'intent_score' => 75,
                'strong_intent_users' => 2,
                'unique_visitors' => 8,
                'sessions' => 12,
                'whatsapp_clicks' => 2,
                'avg_time_on_page' => 80,
                'avg_scroll' => 70,
                'leads' => 1,
            ],
            ['primary_gap_state' => 'healthy_flow']
        );

        $this->assertGreaterThanOrEqual(65, $result['score']);
        $this->assertContains($result['state'], ['strong_contact_signal', 'healthy_contact_flow']);
        $this->assertCount(2, array_slice($result['diagnosis'], 0, 2));
    }

    public function test_it_builds_primary_action_from_optimizer_recommendation(): void
    {
        $service = new ContactProbabilityService();

        $action = $service->primaryRecommendedAction(
            ['score' => 68, 'state' => 'strong_contact_signal'],
            ['primary_action' => [
                'type' => 'test_creative',
                'reason' => 'Baixa atenção do criativo',
                'next_step' => 'Testar novo criativo com foco no preço.',
                'action_key' => 'generate_new_copy',
                'confidence' => 74,
            ]]
        );

        $this->assertSame('test_creative', $action['type']);
        $this->assertSame('Gerar novo criativo', $action['label']);
        $this->assertSame('generate_new_copy', $action['action_key']);
        $this->assertSame(74, $action['confidence']);
    }
}
