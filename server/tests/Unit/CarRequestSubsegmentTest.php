<?php

namespace Tests\Unit;

use App\Http\Requests\CarRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CarRequestSubsegmentTest extends TestCase
{
    /**
     * O subsegmento deixou de ser pedido (abril de 2026): o CarRequest limpa-o sempre em
     * prepareForValidation e a regra é "nullable" em todos os tipos, autocaravana incluída.
     */
    public function test_motorhome_no_longer_requires_subsegment(): void
    {
        $validator = Validator::make(
            $this->validCarPayload([
                'vehicle_type' => 'motorhome',
                'subsegment' => null,
            ]),
            $this->subsegmentRules()
        );

        $this->assertFalse($validator->errors()->has('subsegment'));
    }

    public function test_motorhome_accepts_valid_subsegment(): void
    {
        $validator = Validator::make(
            $this->validCarPayload([
                'vehicle_type' => 'motorhome',
                'subsegment' => 'autocaravana',
            ]),
            $this->subsegmentRules()
        );

        $this->assertFalse($validator->errors()->has('subsegment'));
    }

    public function test_car_does_not_require_subsegment(): void
    {
        $validator = Validator::make(
            $this->validCarPayload([
                'vehicle_type' => 'car',
                'subsegment' => null,
            ]),
            $this->subsegmentRules()
        );

        $this->assertFalse($validator->errors()->has('subsegment'));
    }

    private function validCarPayload(array $overrides = []): array
    {
        return array_merge([
            'status' => 'active',
            'origin' => 'national',
            'registration_year' => now()->year,
            'vehicle_type' => 'car',
            'subsegment' => null,
            'car_brand_id' => 1,
            'car_model_id' => 1,
            'version' => 'Base',
            'fuel_type' => 'Diesel',
            'power_hp' => 150,
            'engine_capacity_cc' => 1995,
            'doors' => 5,
            'transmission' => 'Manual',
            'segment' => 'SUV',
            'seats' => 5,
            'exterior_color' => 'Preto',
            'condition' => 'used',
        ], $overrides);
    }

    private function subsegmentRules(): array
    {
        return Arr::only((new CarRequest())->rules(), ['vehicle_type', 'subsegment']);
    }
}
