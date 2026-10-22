<?php

declare(strict_types=1);

namespace App\Services\Cars\Presentation;

/**
 * O catálogo do equipamento para a apresentação da viatura (a ficha A4, a API pública e, depois,
 * o site do cliente). Junta as duas fontes da plataforma por tema:
 *  · os atributos de habitação com chave fixa (features: has_awning, ...);
 *  · os extras do catálogo automóvel, gravados em TEXTO (web/src/pages/Cars/Car/data/extraGroups.ts):
 *    aqui cada texto do catálogo ganha uma chave fixa e um tema; o mesmo equipamento nas duas fontes
 *    tem a mesma chave e aparece uma vez só. Um texto que não esteja aqui cai em "Outros", tal como vem.
 * Ao acrescentar ou mudar a grafia de um extra em extraGroups.ts, atualizar EXTRAS também.
 *
 * Portado de ~/Projects/quebom/utils/vehicle-presentation.js (cópia de trabalho de 2026-10-19).
 */
final class EquipmentCatalog
{
    /** features (chaves fixas): chave => [rótulo, tema]. */
    public const FEATURES = [
        'has_solar_panel' => ['Painel solar', 'energia'],
        'has_inverter' => ['Inversor', 'energia'],
        'has_gpl' => ['GPL', 'energia'],
        'has_generator' => ['Gerador', 'energia'],
        'has_external_power_socket' => ['Tomada exterior 220V', 'energia'],
        'has_aircon_220v' => ['Ar condicionado 220V', 'energia'],
        'has_viesa' => ['VIESA', 'energia'],
        'has_battery_cutoff' => ['Corta-corrente', 'energia'],
        'has_foldable_table' => ['Mesa rebatível', 'sala'],
        'has_tv' => ['TV', 'sala'],
        'has_tv_support' => ['Suporte TV', 'sala'],
        'has_led_lighting' => ['Iluminação LED', 'sala'],
        'has_halo_lighting' => ['Iluminação halo', 'sala'],
        'has_command_panel' => ['Painel de comandos', 'sala'],
        'has_mosquito_nets' => ['Mosquiteiras nas janelas', 'sala'],
        'has_door_mosquito_net' => ['Mosquiteira na porta', 'sala'],
        'has_panoramic_skylight' => ['Clarabóia panorâmica', 'sala'],
        'has_turbovent_skylight' => ['Clarabóia turbovent', 'sala'],
        'has_40x40_skylight' => ['Clarabóia 40×40', 'sala'],
        'has_window_blackouts' => ['Tapa-luz nas janelas', 'sala'],
        'has_curtains' => ['Cortinas', 'sala'],
        'has_wardrobe' => ['Guarda-fatos', 'sala'],
        'has_rotating_seats' => ['Bancos rotativos', 'cabine'],
        'has_remifront' => ['Remifront', 'cabine'],
        'has_cabin_blackouts' => ['Tapa-luz na cabine', 'cabine'],
        'has_alarm' => ['Alarme', 'cabine'],
        'has_entry_door_lock' => ['Fecho na porta de entrada', 'cabine'],
        'has_cabin_lock' => ['Fecho na cabine', 'cabine'],
        'has_hatch_lock' => ['Fecho no alçapão', 'cabine'],
        'has_gas_lock' => ['Fecho do gás', 'cabine'],
        'has_safe_door' => ['Porta de cofre', 'cabine'],
        'has_stabilizers' => ['Macacos estabilizadores', 'cabine'],
        'has_air_suspension' => ['Suspensão pneumática', 'cabine'],
        'has_air_suspension_compressor' => ['Compressor da suspensão', 'cabine'],
        'has_dual_rear_wheel' => ['Rodado duplo', 'cabine'],
        'has_awning' => ['Toldo', 'exterior'],
        'has_bike_rack' => ['Suporte de bicicletas', 'exterior'],
        'has_motorbike_rack' => ['Suporte de mota', 'exterior'],
        'has_electric_step' => ['Degrau elétrico', 'exterior'],
        'has_manual_step' => ['Degrau manual', 'exterior'],
        'has_national_antenna' => ['Antena nacional', 'exterior'],
        'has_parabolic_antenna' => ['Antena parabólica', 'exterior'],
        'has_spare_wheel' => ['Pneu suplente', 'exterior'],
        'has_bull_eye' => ['Olho de boi', 'exterior'],
        'has_external_wc' => ['Sanita exterior', 'exterior'],
        'has_hubcaps' => ['Tampões', 'exterior'],
        'has_external_ladder' => ['Escada exterior', 'exterior'],
        'has_fix_n_go_kit' => ['Kit Fix\'n\'Go', 'exterior'],
    ];

    /** extras (texto exato do catálogo) => [chave, rótulo, tema]. */
    public const EXTRAS = [
        'Ar condicionado' => ['x_aircon', 'Ar condicionado', 'cabine'],
        'Rádio' => ['x_radio', 'Rádio', 'cabine'],
        'Vidros eléctricos' => ['x_power_windows', 'Vidros elétricos', 'cabine'],
        'Entrada USB' => ['x_usb', 'Entrada USB', 'cabine'],
        'Bluetooth' => ['x_bluetooth', 'Bluetooth', 'cabine'],
        'Ecrã Táctil ou Touch Screen' => ['x_touchscreen', 'Ecrã tátil', 'cabine'],
        'Android Auto' => ['x_android_auto', 'Android Auto', 'cabine'],
        'Computador de Bordo' => ['x_trip_computer', 'Computador de bordo', 'cabine'],
        'Entrada AUX' => ['x_aux', 'Entrada AUX', 'cabine'],
        'Rádio Android' => ['x_android_radio', 'Rádio Android', 'cabine'],
        'Rádio digital (DAB)' => ['x_dab_radio', 'Rádio digital (DAB)', 'cabine'],
        'Carregador de smartphone' => ['x_phone_charger', 'Carregador de smartphone', 'cabine'],
        'Câmara de Marcha Atrás' => ['x_rear_camera', 'Câmara de marcha atrás', 'cabine'],
        'Sensores de marcha atrás' => ['x_rear_sensors', 'Sensores de marcha atrás', 'cabine'],
        'Cruise Control' => ['x_cruise', 'Cruise control', 'cabine'],
        'Direcção Assistida' => ['x_power_steering', 'Direção assistida', 'cabine'],
        'ABS' => ['x_abs', 'ABS', 'cabine'],
        'Airbag do Condutor' => ['x_airbag_driver', 'Airbag do condutor', 'cabine'],
        'Airbag de Passageiros' => ['x_airbag_passenger', 'Airbag do passageiro', 'cabine'],
        'Fecho Central' => ['x_central_lock', 'Fecho central', 'cabine'],
        'Fecho Centralizado com Comando a Distância' => ['x_central_lock_remote', 'Fecho central com comando à distância', 'cabine'],
        'Alertas sobre Cinto de Segurança' => ['x_seatbelt_alert', 'Alerta de cinto de segurança', 'cabine'],
        'Alerta sobre Manutenção' => ['x_service_alert', 'Alerta de manutenção', 'cabine'],
        'ISOFIX' => ['x_isofix', 'ISOFIX', 'cabine'],
        'Start and Stop' => ['x_start_stop', 'Start and Stop', 'cabine'],
        'Alarme' => ['has_alarm', 'Alarme', 'cabine'],
        'Suspensão Pneumatica' => ['has_air_suspension', 'Suspensão pneumática', 'cabine'],
        'Apoio de Braço' => ['x_armrest', 'Apoio de braço', 'cabine'],
        'Bancos Rebativeis' => ['x_folding_seats', 'Bancos rebatíveis', 'cabine'],
        'Bancos rotativos' => ['has_rotating_seats', 'Bancos rotativos', 'cabine'],
        'Volante Multifunções' => ['x_multifunction_wheel', 'Volante multifunções', 'cabine'],
        'Volante com Comandos de Rádio' => ['x_wheel_radio_controls', 'Comandos de rádio no volante', 'cabine'],
        'Mesa Rebativel' => ['has_foldable_table', 'Mesa rebatível', 'sala'],
        'Acabamentos em Madeira' => ['x_wood_finish', 'Acabamentos em madeira', 'sala'],
        'Retrovisores c/ Regulação Eléctrica' => ['x_power_mirrors', 'Retrovisores com regulação elétrica', 'cabine'],
        'Retrovisores Rebativeis Eletricos' => ['x_folding_mirrors', 'Retrovisores rebatíveis elétricos', 'cabine'],
        'Jantes de Liga Leve' => ['x_alloy_wheels', 'Jantes de liga leve', 'exterior'],
        'Faróis Reguláveis em Altura' => ['x_adjustable_headlights', 'Faróis reguláveis em altura', 'exterior'],
        'Faróis de Nevoeiro' => ['x_fog_lights', 'Faróis de nevoeiro', 'exterior'],
        'Faróis Diurnos' => ['x_daytime_lights', 'Faróis diurnos', 'exterior'],
        'Faróis Diurnos Em Led' => ['x_daytime_lights_led', 'Faróis diurnos LED', 'exterior'],
        'Gancho de Reboque' => ['x_tow_hook', 'Gancho de reboque', 'exterior'],
        // Os 17 itens da lista única da cabine das autocaravanas que caíam em "Outros" com o texto
        // antigo (decisão de 22/10): rótulos de web/.../motorhomeCabinList.ts, todos na cabine.
        'ASR' => ['x_asr', 'ASR', 'cabine'],
        'EBD' => ['x_ebd', 'EBD', 'cabine'],
        'Travão de Mão Eléctrico' => ['x_electric_handbrake', 'Travão de mão elétrico', 'cabine'],
        'Retrovisores Aquecidos' => ['x_heated_mirrors', 'Retrovisores aquecidos', 'cabine'],
        'Hill Descent Control' => ['x_hill_descent', 'Controlo de descida (Hill Descent Control)', 'cabine'],
        'Sensores de Chuva' => ['x_rain_sensors', 'Sensores de chuva', 'cabine'],
        'Traction+' => ['x_traction_plus', 'Traction+', 'cabine'],
        'Perfil De Condutores' => ['x_driver_profiles', 'Perfis de condutor', 'cabine'],
        'Deteção Cansaço e Fadiga' => ['x_fatigue_detection', 'Deteção de cansaço e fadiga', 'cabine'],
        'Camara de Frente' => ['x_front_camera', 'Câmara frontal', 'cabine'],
        'Aviso de velocidade' => ['x_speed_warning', 'Aviso de velocidade', 'cabine'],
        'Bancos Dianteiros c/ Memória' => ['x_memory_seats', 'Bancos com memória', 'cabine'],
        'Bancos Dianteiros c/ Regulação Eléctrica' => ['x_electric_seats', 'Bancos com regulação elétrica', 'cabine'],
        'Bancos Dianteiros Aquecidos' => ['x_heated_seats', 'Bancos aquecidos', 'cabine'],
        'Bancos Desportivos' => ['x_sport_seats', 'Bancos desportivos', 'cabine'],
        'Patilhas de Velocidade no Volante' => ['x_paddle_shifters', 'Patilhas de velocidade no volante', 'cabine'],
        'Retrovisor Anti-Encadeamento' => ['x_anti_glare_mirror', 'Retrovisor antiencandeamento', 'cabine'],
        'Retrovisores c/ Anti Encadeamento' => ['x_anti_glare_mirror', 'Retrovisor antiencandeamento', 'cabine'],
        // O carregador sem fios tem chave própria (decisão de 22/10); os dois textos do catálogo.
        'Carregador de smartphone wireless' => ['x_phone_charger_wireless', 'Carregador de smartphone sem fios', 'cabine'],
        'Carregamento Wireless' => ['x_phone_charger_wireless', 'Carregador de smartphone sem fios', 'cabine'],
    ];

    /** Pares que, quando a viatura tem os dois, se mostram num só rótulo. */
    public const MERGES = [
        [['has_tv', 'has_tv_support'], 'TV e suporte TV'],
        [['has_mosquito_nets', 'has_door_mosquito_net'], 'Mosquiteiras nas janelas e na porta'],
        [['x_airbag_driver', 'x_airbag_passenger'], 'Airbags do condutor e passageiro'],
        [['has_rotating_seats', 'x_folding_seats'], 'Bancos rotativos e rebatíveis'],
    ];
}
