<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Country::insert(
            [
                ['name' => 'Albânia', 'code_phone' => '+355'],
                ['name' => 'Alemanha', 'code_phone' => '+49'],
                ['name' => 'Andorra', 'code_phone' => '+376'],
                ['name' => 'Armênia', 'code_phone' => '+374'],
                ['name' => 'Áustria', 'code_phone' => '+43'],
                ['name' => 'Azerbaijão', 'code_phone' => '+994'],
                ['name' => 'Bélgica', 'code_phone' => '+32'],
                ['name' => 'Bielorrússia', 'code_phone' => '+375'],
                ['name' => 'Bósnia e Herzegovina', 'code_phone' => '+387'],
                ['name' => 'Bulgária', 'code_phone' => '+359'],
                ['name' => 'Chipre', 'code_phone' => '+357'],
                ['name' => 'Croácia', 'code_phone' => '+385'],
                ['name' => 'Dinamarca', 'code_phone' => '+45'],
                ['name' => 'Eslováquia', 'code_phone' => '+421'],
                ['name' => 'Eslovênia', 'code_phone' => '+386'],
                ['name' => 'Espanha', 'code_phone' => '+34'],
                ['name' => 'Estônia', 'code_phone' => '+372'],
                ['name' => 'Finlândia', 'code_phone' => '+358'],
                ['name' => 'França', 'code_phone' => '+33'],
                ['name' => 'Geórgia', 'code_phone' => '+995'],
                ['name' => 'Grécia', 'code_phone' => '+30'],
                ['name' => 'Hungria', 'code_phone' => '+36'],
                ['name' => 'Irlanda', 'code_phone' => '+353'],
                ['name' => 'Islândia', 'code_phone' => '+354'],
                ['name' => 'Itália', 'code_phone' => '+39'],
                ['name' => 'Kosovo', 'code_phone' => '+383'],
                ['name' => 'Letônia', 'code_phone' => '+371'],
                ['name' => 'Liechtenstein', 'code_phone' => '+423'],
                ['name' => 'Lituânia', 'code_phone' => '+370'],
                ['name' => 'Luxemburgo', 'code_phone' => '+352'],
                ['name' => 'Malta', 'code_phone' => '+356'],
                ['name' => 'Moldávia', 'code_phone' => '+373'],
                ['name' => 'Mônaco', 'code_phone' => '+377'],
                ['name' => 'Montenegro', 'code_phone' => '+382'],
                ['name' => 'Noruega', 'code_phone' => '+47'],
                ['name' => 'Países Baixos', 'code_phone' => '+31'],
                ['name' => 'Polônia', 'code_phone' => '+48'],
                ['name' => 'Portugal', 'code_phone' => '+351'],
                ['name' => 'Reino Unido', 'code_phone' => '+44'],
                ['name' => 'República Tcheca', 'code_phone' => '+420'],
                ['name' => 'Romênia', 'code_phone' => '+40'],
                ['name' => 'Rússia', 'code_phone' => '+7'],
                ['name' => 'San Marino', 'code_phone' => '+378'],
                ['name' => 'Sérvia', 'code_phone' => '+381'],
                ['name' => 'Suécia', 'code_phone' => '+46'],
                ['name' => 'Suíça', 'code_phone' => '+41'],
                ['name' => 'Turquia', 'code_phone' => '+90'],
                ['name' => 'Ucrânia', 'code_phone' => '+380'],
                ['name' => 'Vaticano', 'code_phone' => '+379'],
            ]
        );
    }
}
