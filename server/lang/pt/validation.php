<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mensagens de validação (pt-PT)
    |--------------------------------------------------------------------------
    |
    | Base: vendor/laravel/framework/.../lang/en/validation.php (todas as rules
    | nativas). Tom: pt-PT (não pt-BR), formal mas directo, "tem de" em vez de
    | "deve ser". Se faltar uma chave aqui, o fallback do framework cai no en.
    | Bloco `attributes` no fim mapeia chaves técnicas → labels pt-PT.
    |
    */

    'accepted' => 'O campo :attribute tem de ser aceite.',
    'accepted_if' => 'O campo :attribute tem de ser aceite quando :other é :value.',
    'active_url' => 'O campo :attribute tem de ser um URL válido.',
    'after' => 'O campo :attribute tem de ser uma data posterior a :date.',
    'after_or_equal' => 'O campo :attribute tem de ser uma data posterior ou igual a :date.',
    'alpha' => 'O campo :attribute só pode conter letras.',
    'alpha_dash' => 'O campo :attribute só pode conter letras, números, traços e underscores.',
    'alpha_num' => 'O campo :attribute só pode conter letras e números.',
    'any_of' => 'O campo :attribute é inválido.',
    'array' => 'O campo :attribute tem de ser uma lista.',
    'ascii' => 'O campo :attribute só pode conter caracteres alfanuméricos e símbolos de um único byte.',
    'before' => 'O campo :attribute tem de ser uma data anterior a :date.',
    'before_or_equal' => 'O campo :attribute tem de ser uma data anterior ou igual a :date.',
    'between' => [
        'array' => 'O campo :attribute tem de ter entre :min e :max elementos.',
        'file' => 'O campo :attribute tem de ter entre :min e :max kilobytes.',
        'numeric' => 'O campo :attribute tem de estar entre :min e :max.',
        'string' => 'O campo :attribute tem de ter entre :min e :max caracteres.',
    ],
    'boolean' => 'O campo :attribute tem de ser verdadeiro ou falso.',
    'can' => 'O campo :attribute contém um valor não autorizado.',
    'confirmed' => 'A confirmação de :attribute não coincide.',
    'contains' => 'Falta um valor obrigatório no campo :attribute.',
    'current_password' => 'A palavra-passe está incorrecta.',
    'date' => 'O campo :attribute tem de ser uma data válida.',
    'date_equals' => 'O campo :attribute tem de ser uma data igual a :date.',
    'date_format' => 'O campo :attribute tem de seguir o formato :format.',
    'decimal' => 'O campo :attribute tem de ter :decimal casas decimais.',
    'declined' => 'O campo :attribute tem de ser recusado.',
    'declined_if' => 'O campo :attribute tem de ser recusado quando :other é :value.',
    'different' => 'Os campos :attribute e :other têm de ser diferentes.',
    'digits' => 'O campo :attribute tem de ter :digits dígitos.',
    'digits_between' => 'O campo :attribute tem de ter entre :min e :max dígitos.',
    'dimensions' => 'O campo :attribute tem dimensões de imagem inválidas.',
    'distinct' => 'O campo :attribute contém um valor duplicado.',
    'doesnt_contain' => 'O campo :attribute não pode conter nenhum dos seguintes: :values.',
    'doesnt_end_with' => 'O campo :attribute não pode terminar com nenhum dos seguintes: :values.',
    'doesnt_start_with' => 'O campo :attribute não pode começar com nenhum dos seguintes: :values.',
    'email' => 'O campo :attribute tem de ser um email válido.',
    'encoding' => 'O campo :attribute tem de estar codificado em :encoding.',
    'ends_with' => 'O campo :attribute tem de terminar com um dos seguintes: :values.',
    'enum' => 'O valor escolhido para :attribute é inválido.',
    'exists' => 'O valor escolhido para :attribute é inválido.',
    'extensions' => 'O campo :attribute tem de ter uma das seguintes extensões: :values.',
    'file' => 'O campo :attribute tem de ser um ficheiro.',
    'filled' => 'O campo :attribute tem de ter um valor.',
    'gt' => [
        'array' => 'O campo :attribute tem de ter mais do que :value elementos.',
        'file' => 'O campo :attribute tem de ser maior do que :value kilobytes.',
        'numeric' => 'O campo :attribute tem de ser superior a :value.',
        'string' => 'O campo :attribute tem de ter mais do que :value caracteres.',
    ],
    'gte' => [
        'array' => 'O campo :attribute tem de ter pelo menos :value elementos.',
        'file' => 'O campo :attribute tem de ser maior ou igual a :value kilobytes.',
        'numeric' => 'O campo :attribute tem de ser superior ou igual a :value.',
        'string' => 'O campo :attribute tem de ter pelo menos :value caracteres.',
    ],
    'hex_color' => 'O campo :attribute tem de ser uma cor hexadecimal válida.',
    'image' => 'O campo :attribute tem de ser uma imagem.',
    'in' => 'O valor escolhido para :attribute é inválido.',
    'in_array' => 'O campo :attribute tem de existir em :other.',
    'in_array_keys' => 'O campo :attribute tem de conter pelo menos uma das seguintes chaves: :values.',
    'integer' => 'O campo :attribute tem de ser um número inteiro.',
    'ip' => 'O campo :attribute tem de ser um endereço IP válido.',
    'ipv4' => 'O campo :attribute tem de ser um endereço IPv4 válido.',
    'ipv6' => 'O campo :attribute tem de ser um endereço IPv6 válido.',
    'json' => 'O campo :attribute tem de ser uma cadeia JSON válida.',
    'list' => 'O campo :attribute tem de ser uma lista.',
    'lowercase' => 'O campo :attribute tem de estar em minúsculas.',
    'lt' => [
        'array' => 'O campo :attribute tem de ter menos do que :value elementos.',
        'file' => 'O campo :attribute tem de ser menor do que :value kilobytes.',
        'numeric' => 'O campo :attribute tem de ser inferior a :value.',
        'string' => 'O campo :attribute tem de ter menos do que :value caracteres.',
    ],
    'lte' => [
        'array' => 'O campo :attribute não pode ter mais do que :value elementos.',
        'file' => 'O campo :attribute tem de ser menor ou igual a :value kilobytes.',
        'numeric' => 'O campo :attribute tem de ser inferior ou igual a :value.',
        'string' => 'O campo :attribute tem de ter no máximo :value caracteres.',
    ],
    'mac_address' => 'O campo :attribute tem de ser um endereço MAC válido.',
    'max' => [
        'array' => 'O campo :attribute não pode ter mais do que :max elementos.',
        'file' => 'O campo :attribute não pode ter mais do que :max kilobytes.',
        'numeric' => 'O campo :attribute não pode ser superior a :max.',
        'string' => 'O campo :attribute não pode ter mais do que :max caracteres.',
    ],
    'max_digits' => 'O campo :attribute não pode ter mais do que :max dígitos.',
    'mimes' => 'O campo :attribute tem de ser um ficheiro do tipo: :values.',
    'mimetypes' => 'O campo :attribute tem de ser um ficheiro do tipo: :values.',
    'min' => [
        'array' => 'O campo :attribute tem de ter pelo menos :min elementos.',
        'file' => 'O campo :attribute tem de ter pelo menos :min kilobytes.',
        'numeric' => 'O campo :attribute não pode ser inferior a :min.',
        'string' => 'O campo :attribute tem de ter pelo menos :min caracteres.',
    ],
    'min_digits' => 'O campo :attribute tem de ter pelo menos :min dígitos.',
    'missing' => 'O campo :attribute não pode estar presente.',
    'missing_if' => 'O campo :attribute não pode estar presente quando :other é :value.',
    'missing_unless' => 'O campo :attribute não pode estar presente excepto quando :other é :value.',
    'missing_with' => 'O campo :attribute não pode estar presente quando :values está presente.',
    'missing_with_all' => 'O campo :attribute não pode estar presente quando :values estão presentes.',
    'multiple_of' => 'O campo :attribute tem de ser múltiplo de :value.',
    'not_in' => 'O valor escolhido para :attribute é inválido.',
    'not_regex' => 'O formato do campo :attribute é inválido.',
    'numeric' => 'O campo :attribute tem de ser um número.',
    'password' => [
        'letters' => 'O campo :attribute tem de conter pelo menos uma letra.',
        'mixed' => 'O campo :attribute tem de conter pelo menos uma letra maiúscula e uma minúscula.',
        'numbers' => 'O campo :attribute tem de conter pelo menos um número.',
        'symbols' => 'O campo :attribute tem de conter pelo menos um símbolo.',
        'uncompromised' => 'O valor de :attribute apareceu numa fuga de dados conhecida. Escolhe outro valor.',
    ],
    'present' => 'O campo :attribute tem de estar presente.',
    'present_if' => 'O campo :attribute tem de estar presente quando :other é :value.',
    'present_unless' => 'O campo :attribute tem de estar presente excepto quando :other é :value.',
    'present_with' => 'O campo :attribute tem de estar presente quando :values está presente.',
    'present_with_all' => 'O campo :attribute tem de estar presente quando :values estão presentes.',
    'prohibited' => 'O campo :attribute não é permitido.',
    'prohibited_if' => 'O campo :attribute não é permitido quando :other é :value.',
    'prohibited_if_accepted' => 'O campo :attribute não é permitido quando :other é aceite.',
    'prohibited_if_declined' => 'O campo :attribute não é permitido quando :other é recusado.',
    'prohibited_unless' => 'O campo :attribute não é permitido excepto quando :other está em :values.',
    'prohibits' => 'O campo :attribute impede que :other esteja presente.',
    'regex' => 'O formato do campo :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'required_array_keys' => 'O campo :attribute tem de conter entradas para: :values.',
    'required_if' => 'O campo :attribute é obrigatório quando :other é :value.',
    'required_if_accepted' => 'O campo :attribute é obrigatório quando :other é aceite.',
    'required_if_declined' => 'O campo :attribute é obrigatório quando :other é recusado.',
    'required_unless' => 'O campo :attribute é obrigatório excepto quando :other está em :values.',
    'required_with' => 'O campo :attribute é obrigatório quando :values está presente.',
    'required_with_all' => 'O campo :attribute é obrigatório quando :values estão presentes.',
    'required_without' => 'O campo :attribute é obrigatório quando :values não está presente.',
    'required_without_all' => 'O campo :attribute é obrigatório quando nenhum de :values está presente.',
    'same' => 'Os campos :attribute e :other têm de ser iguais.',
    'size' => [
        'array' => 'O campo :attribute tem de conter :size elementos.',
        'file' => 'O campo :attribute tem de ter :size kilobytes.',
        'numeric' => 'O campo :attribute tem de ser :size.',
        'string' => 'O campo :attribute tem de ter :size caracteres.',
    ],
    'starts_with' => 'O campo :attribute tem de começar com um dos seguintes: :values.',
    'string' => 'O campo :attribute tem de ser texto.',
    'timezone' => 'O campo :attribute tem de ser um fuso horário válido.',
    'unique' => 'O valor de :attribute já está em uso.',
    'uploaded' => 'Falhou o carregamento do ficheiro :attribute.',
    'uppercase' => 'O campo :attribute tem de estar em maiúsculas.',
    'url' => 'O campo :attribute tem de ser um URL válido.',
    'ulid' => 'O campo :attribute tem de ser um ULID válido.',
    'uuid' => 'O campo :attribute tem de ser um UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Mensagens custom por (atributo + rule)
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Labels dos atributos
    |--------------------------------------------------------------------------
    |
    | Substitui :attribute por algo legível em pt-PT em vez do auto-prettify do
    | Laravel (que pega na chave e troca _ por espaço). Cobre top-level dos
    | Form Requests + os nested mais comuns de vehicle_attributes (dimensões,
    | pesos, litros — os que tipicamente disparam erros de min/max).
    |
    */

    'attributes' => [
        // ── cars (top-level)
        'vehicle_type'      => 'tipo de veículo',
        'car_brand_id'      => 'marca',
        'car_model_id'      => 'modelo',
        'car_category_id'   => 'categoria',
        'registration_year' => 'ano',
        'registration_month'=> 'mês de registo',
        'fuel_type'         => 'combustível',
        'transmission'      => 'caixa',
        'power_hp'          => 'potência',
        'engine_capacity_cc'=> 'cilindrada',
        'cylinders'         => 'cilindros',
        'doors'             => 'portas',
        'seats'             => 'lugares',
        'segment'           => 'segmento',
        'subsegment'        => 'subsegmento',
        'condition'         => 'condição',
        'origin'            => 'origem',
        'mileage_km'        => 'quilometragem',
        'license_plate'     => 'matrícula',
        'vin'               => 'VIN',
        'version'           => 'versão',
        'exterior_color'    => 'cor exterior',
        'interior_color'    => 'cor interior',
        'price_gross'       => 'preço com IVA',
        'promo_price_gross' => 'preço promo',
        'price_net'         => 'preço sem IVA',
        'hide_price_online' => 'preço sob consulta',
        'monthly_payment'   => 'mensalidade',
        'status'            => 'estado',
        'images'            => 'imagens',

        // ── vehicle_attributes (nested mais comuns)
        'vehicle_attributes.dimensions.length_m'                              => 'comprimento (m)',
        'vehicle_attributes.dimensions.width_m'                               => 'largura (m)',
        'vehicle_attributes.dimensions.height_m'                              => 'altura (m)',
        'vehicle_attributes.weights.gross_weight_kg'                          => 'peso bruto (kg)',
        'vehicle_attributes.weights.tare_kg'                                  => 'tara (kg)',
        'vehicle_attributes.weights.towable_weight_kg'                        => 'peso de reboque (kg)',
        'vehicle_attributes.habitation_basics.kitchen.fridge_litres'          => 'litros do frigorífico',
        'vehicle_attributes.habitation_basics.kitchen.fridge_shelves'         => 'prateleiras do frigorífico',
        'vehicle_attributes.habitation_basics.bathroom.clean_water_litres'    => 'litros água limpa',
        'vehicle_attributes.habitation_basics.bathroom.waste_water_litres'    => 'litros água residual',
        'vehicle_attributes.energy_climate.solar_panel_count'                 => 'nº de painéis solares',
        'vehicle_attributes.energy_climate.solar_panel_watts'                 => 'potência painel solar (W)',
        'vehicle_attributes.energy_climate.inverter_watts'                    => 'potência inversor (W)',
        'vehicle_attributes.autonomy_km'                                      => 'autonomia (km)',

        // ── venda
        'sale_price'      => 'preço de venda',
        'buyer_name'      => 'nome do comprador',
        'buyer_phone'     => 'telefone do comprador',
        'buyer_email'     => 'email do comprador',
        'sale_channel'    => 'canal de venda',
        'contact_consent' => 'consentimento de contacto',
        'notes'           => 'notas',
    ],

];
