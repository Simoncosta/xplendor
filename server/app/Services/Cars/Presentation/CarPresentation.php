<?php

declare(strict_types=1);

namespace App\Services\Cars\Presentation;

use Normalizer;

/**
 * A apresentação de uma viatura, montada num só sítio (a ficha A4 de impressão, a API pública e,
 * depois, o site do cliente): o nome, a linha do cabeçalho, o preço, os números-chave, os blocos
 * temáticos (detalhes, itens com ✓ sem duplicados, camas com explicação) e a descrição.
 *
 * Recebe a viatura no formato da API pública (CarPublicResource) e segue, regra a regra, a lógica
 * do site da Quebom (~/Projects/quebom/utils/vehicle-presentation.js, cópia de trabalho de
 * 2026-10-19): as mesmas entradas dão os mesmos blocos e itens (CarPresentationTest compara-o
 * com saídas gravadas do próprio site). Diferenças, só para tipos que o site não tem:
 *  · carros e motos: o bloco "Motor e condução" e os quilómetros aparecem (o site só tem
 *    autocaravanas e caravanas); os blocos da habitação nunca; o equipamento de habitação que
 *    venha nos extras vai para "Outros"; o bloco da cabine chama-se "Condução e segurança".
 * Diferenças decididas a 22/10 (o site da Quebom muda na sessão própria): o bloco WC chama-se
 * "Duche e WC" e os 17 itens da lista única da cabine saem na cabine com o rótulo da plataforma.
 * Nada de JSX nem HTML: só dados prontos a desenhar.
 */
final class CarPresentation
{
    public const VERSION = 'presentation-v1';

    public const COLORS = [
        'blue' => 'Azul', 'light-blue' => 'Azul Claro', 'yellow' => 'Amarelo', 'dark-blue' => 'Azul Escuro', 'beige' => 'Bege',
        'bordeaux' => 'Bordeaux', 'white' => 'Branco', 'brown' => 'Castanho', 'champagne' => 'Champagne', 'gray' => 'Cinza',
        'dark-gray' => 'Cinza Antracite', 'silver-gray' => 'Cinza Prata', 'orange' => 'Laranja', 'other' => 'Outras', 'black' => 'Preto',
        'pink' => 'Rosa', 'purple' => 'Roxo', 'light-green' => 'Verde Claro', 'dark-green' => 'Verde Escuro', 'red' => 'Vermelho',
    ];
    public const FUEL = ['electric' => 'Elétrico', 'gasoline' => 'Gasolina', 'diesel' => 'Diesel'];
    /** Só nos carros e motos (o site não os tem): os restantes combustíveis da plataforma. */
    public const FUEL_ROAD = [
        'petrol' => 'Gasolina', 'gaz' => 'Gasolina', 'hybrid' => 'Híbrido', 'hibride-gaz' => 'Híbrido (gasolina)', 'hibride-diesel' => 'Híbrido (diesel)',
        'plug-in-hybrid' => 'Híbrido plug-in', 'plugin-hybrid' => 'Híbrido plug-in', 'plug_in_hybrid' => 'Híbrido plug-in', 'lpg' => 'GPL', 'gpl' => 'GPL', 'cng' => 'GNC',
    ];
    public const TRANSMISSION = ['manual' => 'Manual', 'automatic' => 'Automática', 'semi_automatic' => 'Semiautomática'];
    public const FRIDGE = ['trivalent' => 'Trivalente', 'compressor' => 'Compressor', 'absorption' => 'Absorção'];
    public const STOVE = ['gas' => 'Gás', 'electric' => 'Elétrico', 'mixed' => 'Misto', 'induction' => 'Indução'];
    public const SHOWER = ['separate' => 'Separado', 'independent' => 'Independente', 'combined' => 'Combinado'];
    public const LIVING_ROOM = ['face_to_face' => 'Frente a frente', 'l_shape' => 'Sala em L', 'panoramic' => 'Salão panorâmico', 'double_dinette' => 'Sala dupla'];
    public const HEAT_SOURCE = ['electric' => 'Elétrico', 'gas' => 'Gás', 'diesel' => 'Gasóleo', 'none' => 'Nenhum'];
    public const CHASSIS = ['standard' => 'Standard', 'alko' => 'Alko', 'other' => 'Outro'];
    public const UPHOLSTERY = ['excellent' => 'Excelente / como novo', 'good' => 'Bom', 'fair' => 'Razoável', 'worn' => 'Desgastado', 'replaced' => 'Substituído'];
    public const INVERTER = ['pure_sine' => 'Onda pura', 'modified_sine' => 'Onda modificada'];
    public const ORIGIN = ['national' => 'Nacional', 'imported' => 'Importado'];
    public const CONDITION = ['new' => 'Novo', 'used' => 'Usado', 'like_new' => 'Como novo', 'good' => 'Bom estado', 'service' => 'Serviço', 'trade_in' => 'Retoma', 'classic' => 'Clássico'];
    public const CATEGORY_LABELS = ['Campervan' => 'Van'];
    public const TYPE_LABELS = ['motorhome' => 'Autocaravana', 'caravan' => 'Caravana', 'car' => 'Automóvel', 'motorcycle' => 'Motociclo'];
    public const BED_DESCRIPTIONS = [
        'cama_convertivel' => 'Mesa que se transforma em cama', 'cama_transversal' => 'Atravessada na largura, ao fundo da viatura',
        'cama_central' => 'Cama de casal com acesso pelos dois lados', 'cama_francesa' => 'Cama de casal encostada a um dos lados',
        'cama_basculante' => 'Desce do teto quando é precisa', 'cama_garagem' => 'Elevada, por cima da garagem traseira',
        'cama_capucino' => 'Por cima da cabine de condução', 'cama_elevatoria_eletrica' => 'Sobe e desce com comando elétrico',
        'beliche' => 'Camas sobrepostas', 'cama_popup_panoramica' => 'No teto elevatório, com vista panorâmica',
    ];
    public const BED_FALLBACK_LABELS = ['cama_popup_panoramica' => 'Cama pop-up panorâmica'];
    /** Os títulos dos blocos (o da cabine depende do tipo: ver sectionTitle). "Duche e WC": decisão de 22/10. */
    public const SECTION_TITLES = [
        'motor' => 'Motor e condução', 'dimensoes' => 'Dimensões e peso', 'camas' => 'Camas', 'energia' => 'Energia e autonomia',
        'sala' => 'Sala e cozinha', 'wc' => 'Duche e WC', 'exterior' => 'Exterior', 'condicao' => 'Condição', 'outros' => 'Outros',
    ];

    /** @param array<string, mixed> $v a viatura no formato da API pública */
    public static function fromPublic(array $v): array
    {
        $type = (string) ($v['vehicle_type'] ?? '');

        return [
            'version' => self::VERSION,
            'display_name' => self::displayName($v),
            'eyebrow' => self::eyebrow($v),
            'meta' => self::headerMeta($v),
            'price' => self::price($v),
            'key_figures' => self::keyFigures($v),
            'sections' => self::sections($v),
            'description_title' => match ($type) {
                'motorhome' => 'Sobre esta autocaravana', 'caravan' => 'Sobre esta caravana', 'motorcycle' => 'Sobre este motociclo', default => 'Sobre esta viatura',
            },
            'description' => self::has($v['description_pt'] ?? null) && trim((string) $v['description_pt']) !== '' ? trim((string) $v['description_pt']) : null,
        ];
    }

    // ── Utilitários (com a semântica do JavaScript do site) ─────────────────

    private static function has(mixed $v): bool
    {
        return $v !== null && $v !== '';
    }

    /** Number(v) finito e maior que zero (true conta como 1, como no JavaScript). */
    private static function isPositive(mixed $v): bool
    {
        return self::num($v) !== null;
    }

    private static function num(mixed $v): int|float|null
    {
        if ($v === true) {
            return 1;
        }
        if (is_int($v) || is_float($v)) {
            return is_finite((float) $v) && $v > 0 ? $v : null;
        }
        if (is_string($v) && trim($v) !== '' && is_numeric(trim($v))) {
            $n = trim($v) + 0;

            return $n > 0 ? $n : null;
        }

        return null;
    }

    private static function str(int|float $n): string
    {
        return (floor((float) $n) == $n) ? (string) (int) $n : rtrim(rtrim(number_format((float) $n, 10, '.', ''), '0'), '.');
    }

    /** "12345" → "12 345" (espaço inseparável), como o formatInt do site. */
    private static function formatInt(mixed $n): string
    {
        $s = (string) (int) round((float) $n);

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', "\u{00a0}", $s) ?? $s;
    }

    private static function formatMeters(mixed $v): ?string
    {
        return self::isPositive($v) ? str_replace('.', ',', number_format((float) self::num($v), 2, '.', '')) . ' m' : null;
    }

    /** @param list<mixed> $bits */
    private static function joinBits(array $bits): string
    {
        return implode(' · ', array_values(array_filter($bits, fn ($b) => $b !== null && $b !== false && $b !== '' && $b !== 0)));
    }

    private static function plural(int|float $n, string $one, string $many): string
    {
        return $n == 1 ? $one : $many;
    }

    private static function pair(string $label, mixed $value): ?array
    {
        return self::has($value) ? ['label' => $label, 'value' => $value] : null;
    }

    private static function truthy(mixed $v): bool
    {
        return $v !== null && $v !== false && $v !== 0 && $v !== 0.0 && $v !== '';
    }

    /** @param list<?array> $rows */
    private static function rows(array $rows): array
    {
        return array_values(array_filter($rows));
    }

    private static function isHabitation(array $v): bool
    {
        return in_array($v['vehicle_type'] ?? null, ['motorhome', 'caravan'], true);
    }

    // ── Equipamento (features e extras), sem duplicados, por tema ────────────

    /** @return array{energia: list<string>, sala: list<string>, cabine: list<string>, exterior: list<string>, outros: list<string>} */
    public static function equipmentByTheme(array $v): array
    {
        $f = is_array($v['features'] ?? null) ? $v['features'] : [];
        $items = [];
        $seen = [];
        $add = function (string $id, string $label, string $theme) use (&$items, &$seen) {
            if (isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $items[] = ['id' => $id, 'label' => $label, 'theme' => $theme];
        };
        foreach (EquipmentCatalog::FEATURES as $key => [$label, $theme]) {
            if (self::truthy($f[$key] ?? null)) {
                $add($key, $label, $theme);
            }
        }
        foreach ((is_array($v['extras'] ?? null) ? $v['extras'] : []) as $group) {
            foreach ((is_array($group['items'] ?? null) ? $group['items'] : []) as $raw) {
                $text = is_string($raw) ? trim($raw) : '';
                if ($text === '') {
                    continue;
                }
                $mapped = EquipmentCatalog::EXTRAS[$text] ?? null;
                $mapped ? $add($mapped[0], $mapped[1], $mapped[2]) : $add("x:{$text}", $text, 'outros');
            }
        }
        foreach (EquipmentCatalog::MERGES as [$ids, $label]) {
            $idx = array_map(fn ($id) => array_search($id, array_column($items, 'id'), true), $ids);
            if (! in_array(false, $idx, true)) {
                $items[$idx[0]]['label'] = $label;
                foreach (array_slice($idx, 1) as $i) {
                    unset($items[$i]);
                }
                $items = array_values($items);
            }
        }
        $by = ['energia' => [], 'sala' => [], 'cabine' => [], 'exterior' => [], 'outros' => []];
        $habitation = self::isHabitation($v);
        foreach ($items as $it) {
            $theme = isset($by[$it['theme']]) ? $it['theme'] : 'outros';
            // Carros e motos: sem blocos da habitação; esse equipamento fica em "Outros".
            if (! $habitation && in_array($theme, ['energia', 'sala'], true)) {
                $theme = 'outros';
            }
            $by[$theme][] = $it['label'];
        }

        return $by;
    }

    public static function sectionTitle(string $id, ?string $vehicleType): string
    {
        if ($id === 'cabine') {
            return match ($vehicleType) {
                'caravan' => 'Segurança', 'motorhome' => 'Cabine de condução e segurança', default => 'Condução e segurança',
            };
        }

        return self::SECTION_TITLES[$id] ?? self::SECTION_TITLES['outros'];
    }

    /**
     * Um extra (texto gravado) como aparece na apresentação: a chave, o rótulo e o bloco, com as
     * mesmas regras de equipmentByTheme. Para a API pública (items_detail), sem mudar o texto.
     *
     * @return array{text: string, key: string, label: string, section: string, section_title: string}
     */
    public static function extraDetail(string $text, ?string $vehicleType): array
    {
        $text = trim($text);
        [$key, $label, $theme] = EquipmentCatalog::EXTRAS[$text] ?? ["x:{$text}", $text, 'outros'];
        $habitation = in_array($vehicleType, ['motorhome', 'caravan'], true);
        if (! in_array($theme, ['energia', 'sala', 'cabine', 'exterior'], true) || (! $habitation && in_array($theme, ['energia', 'sala'], true))) {
            $theme = 'outros';
        }

        return ['text' => $text, 'key' => $key, 'label' => $label, 'section' => $theme, 'section_title' => self::sectionTitle($theme, $vehicleType)];
    }

    // ── Cabeçalho ───────────────────────────────────────────────────────────

    private static function normalizeName(mixed $s): string
    {
        $s = mb_strtolower((string) ($s ?? ''), 'UTF-8');
        $s = str_replace(['ö', 'ü', 'ä'], ['oe', 'ue', 'ae'], $s);
        $s = class_exists(Normalizer::class) ? (string) Normalizer::normalize($s, Normalizer::FORM_D) : $s;
        $s = preg_replace('/[\x{0300}-\x{036f}]/u', '', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;

        return trim($s);
    }

    public static function displayName(array $v): string
    {
        $brandModel = implode(' ', array_values(array_filter([$v['brand']['name'] ?? null, $v['model']['name'] ?? null], fn ($x) => self::has($x))));
        $title = trim((string) ($v['title'] ?? ''));
        if ($brandModel !== '' && ($title === '' || self::normalizeName($title) === self::normalizeName($brandModel))) {
            return $brandModel;
        }

        return $title !== '' ? $title : ($brandModel !== '' ? $brandModel : 'Viatura');
    }

    /** "tipologia · ano · origem · cor", como no site. */
    public static function headerMeta(array $v): array
    {
        $color = ! empty($v['exterior_color']) ? (self::COLORS[$v['exterior_color']] ?? $v['exterior_color']) : null;
        $cat = $v['category']['name'] ?? null;

        return array_values(array_filter([
            $cat ? (self::CATEGORY_LABELS[$cat] ?? $cat) : null,
            self::has($v['year'] ?? null) ? (string) $v['year'] : null,
            ! empty($v['origin']) ? (self::ORIGIN[$v['origin']] ?? $v['origin']) : null,
            $color ? (! empty($v['is_metallic']) ? "{$color} metalizado" : $color) : null,
        ]));
    }

    /** A linha de cima da ficha: tipologia (ou o tipo) · estado · referência. */
    public static function eyebrow(array $v): array
    {
        $cat = $v['category']['name'] ?? null;

        return array_values(array_filter([
            $cat ? (self::CATEGORY_LABELS[$cat] ?? $cat) : (self::TYPE_LABELS[$v['vehicle_type'] ?? ''] ?? null),
            ! empty($v['condition']) ? (self::CONDITION[$v['condition']] ?? $v['condition']) : null,
            isset($v['id']) ? 'Ref. ' . $v['id'] : null,
        ]));
    }

    /** O preço como no site: escondido ("Sob consulta"), em promoção ou normal. Valores em euros. */
    public static function price(array $v): array
    {
        $hidden = ($v['hide_price_online'] ?? false) === true;
        $promo = ! $hidden && ! empty($v['has_promo_price']) && ! empty($v['price_promo']);
        $regular = isset($v['price_gross']) ? (float) $v['price_gross'] : null;

        return [
            'hidden' => $hidden,
            'promo' => $promo,
            'regular' => $hidden ? null : $regular,
            'current' => $hidden ? null : ($promo ? (float) $v['price_promo'] : $regular),
            'label' => $hidden ? 'Sob consulta' : null,
        ];
    }

    // ── Números-chave ───────────────────────────────────────────────────────

    private static function count(mixed $v): ?int
    {
        if (is_int($v) && $v > 0) {
            return $v;
        }

        return is_float($v) && $v > 0 && floor($v) == $v ? (int) $v : null;
    }

    public static function keyFigures(array $v): array
    {
        $specs = is_array($v['specs'] ?? null) ? $v['specs'] : [];
        $seats = self::count($specs['seats'] ?? null);
        $sleeps = self::count($specs['sleeps'] ?? null);
        $type = $v['vehicle_type'] ?? null;
        $km = in_array($type, ['motorhome', 'car', 'motorcycle'], true) && self::isPositive($v['mileage_km'] ?? null) ? self::num($v['mileage_km']) : null;
        $warranty = self::num($v['warranty_months'] ?? null);

        return array_values(array_filter([
            $km !== null ? ['key' => 'km', 'value' => self::formatInt($km) . ' km', 'caption' => 'Quilómetros'] : null,
            $seats !== null ? ['key' => 'seats', 'value' => "{$seats} " . self::plural($seats, 'lugar', 'lugares'), 'caption' => 'Para viajar'] : null,
            $sleeps !== null ? ['key' => 'sleeps', 'value' => "{$sleeps} " . self::plural($sleeps, 'dormida', 'dormidas'), 'caption' => 'Para dormir'] : null,
            $warranty !== null ? ['key' => 'warranty', 'value' => self::str($warranty) . ' ' . self::plural($warranty, 'mês', 'meses'), 'caption' => 'Garantia'] : null,
        ]));
    }

    // ── Blocos temáticos ────────────────────────────────────────────────────

    /** Os blocos, pela ordem do site e sem os vazios. */
    public static function sections(array $v): array
    {
        $themed = self::equipmentByTheme($v);
        $all = [
            self::engine($v), self::dimensions($v), self::beds($v), self::energy($v, $themed), self::living($v, $themed),
            self::wc($v), self::cabin($v, $themed), self::exterior($v, $themed), self::condition($v), self::others($themed),
        ];

        return array_values(array_filter($all, fn ($s) => $s && ($s['details'] !== [] || $s['items'] !== [] || ! empty($s['beds']))));
    }

    private static function engine(array $v): ?array
    {
        $type = $v['vehicle_type'] ?? null;
        if (! in_array($type, ['motorhome', 'car', 'motorcycle'], true)) {
            return null;
        }
        $fuels = $type === 'motorhome' ? self::FUEL : self::FUEL + self::FUEL_ROAD;
        $brand = $v['engine_brand'] ?? null;
        $brand = is_array($brand) ? ($brand['name'] ?? null) : $brand;
        $brand = is_string($brand) && trim($brand) !== '' ? trim($brand) : null;
        $gears = self::num($v['gear_count'] ?? null);

        return ['id' => 'motor', 'title' => 'Motor e condução', 'details' => self::rows([
            self::pair('Motor', self::joinBits([$brand, $fuels[$v['fuel_type'] ?? ''] ?? null]) ?: null),
            self::pair('Caixa', self::joinBits([
                ! empty($v['transmission']) ? (self::TRANSMISSION[$v['transmission']] ?? $v['transmission']) : null,
                $gears ? self::str($gears) . ' ' . self::plural($gears, 'velocidade', 'velocidades') : null,
            ]) ?: null),
            self::pair('Potência', self::isPositive($v['power_hp'] ?? null) ? self::str(self::num($v['power_hp'])) . ' cv' : null),
            self::pair('Cilindrada', self::isPositive($v['engine_capacity_cc'] ?? null) ? self::formatInt($v['engine_capacity_cc']) . ' cc' : null),
        ]), 'items' => []];
    }

    private static function dimensions(array $v): array
    {
        $s = is_array($v['specs'] ?? null) ? $v['specs'] : [];

        return ['id' => 'dimensoes', 'title' => 'Dimensões e peso', 'details' => self::rows([
            self::pair('Comprimento', self::formatMeters($s['length_m'] ?? null)),
            self::pair('Largura', self::formatMeters($s['width_m'] ?? null)),
            self::pair('Altura', self::formatMeters($s['height_m'] ?? null)),
            self::pair('Peso', self::isPositive($s['gross_weight_kg'] ?? null) ? self::formatInt($s['gross_weight_kg']) . ' kg' : null),
        ]), 'items' => []];
    }

    public static function bedLabel(?string $type, mixed $apiLabel): string
    {
        $label = is_string($apiLabel) ? trim($apiLabel) : '';
        if ($label !== '' && $label !== $type) {
            return $label;
        }
        $code = $type ?: $label;

        return self::BED_FALLBACK_LABELS[$type ?? ''] ?? ($code !== '' ? mb_strtoupper(mb_substr($code, 0, 1)) . str_replace('_', ' ', mb_substr($code, 1)) : '');
    }

    private static function beds(array $v): array
    {
        $out = [];
        foreach ((is_array($v['beds'] ?? null) ? $v['beds'] : []) as $b) {
            if (is_string($b)) {
                $out[] = ['label' => $b, 'description' => null];
                continue;
            }
            $label = self::bedLabel($b['type'] ?? null, $b['label'] ?? null);
            if ($label !== '') {
                $out[] = ['label' => $label, 'description' => self::BED_DESCRIPTIONS[$b['type'] ?? ''] ?? null];
            }
        }

        return ['id' => 'camas', 'title' => 'Camas', 'details' => [], 'items' => [], 'beds' => $out];
    }

    private static function energy(array $v, array $themed): array
    {
        $f = is_array($v['features'] ?? null) ? $v['features'] : [];
        $s = is_array($v['specs'] ?? null) ? $v['specs'] : [];
        $d = [];
        $solarCount = self::num($f['solar_panel_count'] ?? null);
        $solarWatts = self::num($f['solar_panel_watts'] ?? null);
        $solar = $solarWatts ? self::joinBits([$solarCount ? self::str($solarCount) : null, self::formatInt($solarWatts) . ' W'])
            : ($solarCount ? self::str($solarCount) . ' ' . self::plural($solarCount, 'painel', 'painéis') : '');
        if ($solar !== '') {
            $d[] = ['label' => 'Painel solar', 'value' => $solar];
        }
        $inverter = self::joinBits([
            ! empty($f['inverter_type']) ? (self::INVERTER[$f['inverter_type']] ?? $f['inverter_type']) : null,
            self::num($f['inverter_watts'] ?? null) ? self::formatInt($f['inverter_watts']) . ' W' : null,
        ]);
        if ($inverter !== '') {
            $d[] = ['label' => 'Inversor', 'value' => $inverter];
        }
        $cell = self::num($f['cell_battery_count'] ?? null);
        $cabin = self::num($f['cabin_battery_count'] ?? null);
        if ($cell) {
            $d[] = ['label' => self::plural($cell, 'Bateria da célula', 'Baterias da célula'), 'value' => self::str($cell)];
        }
        $cabinBrand = self::has($f['cabin_battery_brand'] ?? null) ? trim((string) $f['cabin_battery_brand']) : '';
        $cabinAh = self::num($f['cabin_battery_ah'] ?? null);
        $cabinValue = self::joinBits([$cabin ? self::str($cabin) : null, $cabinBrand ?: null, $cabinAh ? self::formatInt($cabinAh) . ' Ah' : null]);
        if ($cabinValue !== '') {
            $d[] = ['label' => self::plural($cabin ?? 2, 'Bateria da cabine', 'Baterias da cabine'), 'value' => $cabinValue];
        }
        if (! $cell && ! $cabin && self::num($f['battery_count'] ?? null)) {
            $d[] = ['label' => 'Baterias', 'value' => self::str(self::num($f['battery_count']))];
        }
        $lc = self::num($f['lithium_battery_count'] ?? null);
        $la = self::num($f['lithium_battery_ah'] ?? null);
        if ($lc || $la) {
            $d[] = ['label' => 'Bateria de lítio', 'value' => self::joinBits([$lc ? self::str($lc) . ' un.' : null, $la ? self::str($la) . ' Ah' : null])];
        }
        $gpl = self::num($f['gpl_bottles_count'] ?? null);
        if ($gpl) {
            $d[] = ['label' => 'Garrafas de GPL', 'value' => self::str($gpl)];
        }
        if (! empty($f['water_heater_source']) && $f['water_heater_source'] !== 'none') {
            $d[] = ['label' => 'Aquecimento de água', 'value' => self::joinBits([self::HEAT_SOURCE[$f['water_heater_source']] ?? $f['water_heater_source'], $f['water_heater_brand'] ?? null])];
        }
        if (! empty($f['ambient_heating_source']) && $f['ambient_heating_source'] !== 'none') {
            $d[] = ['label' => 'Aquecimento ambiente', 'value' => self::joinBits([self::HEAT_SOURCE[$f['ambient_heating_source']] ?? $f['ambient_heating_source'], $f['ambient_heating_brand'] ?? null])];
        }
        if (self::isPositive($s['autonomy_km'] ?? null)) {
            $d[] = ['label' => 'Autonomia', 'value' => "{$s['autonomy_km']} km"];
        }
        $redundant = array_filter([$solar !== '' ? 'Painel solar' : null, $inverter !== '' ? 'Inversor' : null, $gpl ? 'GPL' : null]);
        $items = array_values(array_filter($themed['energia'], fn ($l) => ! in_array($l, $redundant, true)));

        return ['id' => 'energia', 'title' => self::SECTION_TITLES['energia'], 'details' => $d, 'items' => $items];
    }

    private static function living(array $v, array $themed): array
    {
        $h = is_array($v['habitation'] ?? null) ? $v['habitation'] : [];
        $f = is_array($v['features'] ?? null) ? $v['features'] : [];
        $d = [];
        $fridge = self::joinBits([
            ! empty($h['fridge_type']) ? (self::FRIDGE[$h['fridge_type']] ?? $h['fridge_type']) : null,
            self::has($h['fridge_litres'] ?? null) ? "{$h['fridge_litres']} L" : null,
            self::has($h['fridge_brand'] ?? null) ? trim((string) $h['fridge_brand']) : null,
        ]);
        if ($fridge !== '') {
            $d[] = ['label' => 'Frigorífico', 'value' => $fridge];
        }
        $burners = self::num($h['stove_burners'] ?? null);
        $stove = self::joinBits([
            $burners ? self::str($burners) . ' ' . self::plural($burners, 'boca', 'bocas') : null,
            ! empty($h['stove_type']) ? (self::STOVE[$h['stove_type']] ?? $h['stove_type']) : null,
        ]);
        if ($stove !== '') {
            $d[] = ['label' => 'Fogão', 'value' => $stove];
        }
        $extractor = self::has($h['extractor_brand'] ?? null) ? trim((string) $h['extractor_brand']) : '';
        if ($extractor !== '') {
            $d[] = ['label' => 'Exaustor', 'value' => $extractor];
        }
        $layout = $f['living_room_layout'] ?? ($v['living_room']['layout'] ?? ($v['living_room_layout'] ?? null));
        if (self::has($layout)) {
            $d[] = ['label' => 'Sala', 'value' => self::LIVING_ROOM[$layout] ?? $layout];
        }
        if (! empty($f['upholstery_state'])) {
            $d[] = ['label' => 'Estofos', 'value' => self::UPHOLSTERY[$f['upholstery_state']] ?? $f['upholstery_state']];
        }
        $tv = self::num($f['tv_count'] ?? null);
        if ($tv || ! empty($f['tv_location'])) {
            $d[] = ['label' => 'TV', 'value' => self::joinBits([$tv ? self::str($tv) . ' un.' : null, ! empty($f['tv_location']) ? trim((string) $f['tv_location']) : null])];
        }
        $extending = $f['has_extending_table'] ?? ($v['living_room']['has_extending_table'] ?? ($v['has_extending_table'] ?? null));
        $items = array_values(array_filter([
            self::truthy($h['has_stove'] ?? null) && $stove === '' ? 'Fogão' : null,
            self::truthy($h['has_oven'] ?? null) ? 'Forno' : null,
            self::truthy($h['has_microwave'] ?? null) ? 'Micro-ondas' : null,
            self::truthy($h['has_extractor'] ?? null) && $extractor === '' ? 'Exaustor' : null,
            self::truthy($h['has_fridge'] ?? null) && $fridge === '' ? 'Frigorífico' : null,
            self::truthy($extending) ? 'Mesa telescópica' : null,
        ]));

        return ['id' => 'sala', 'title' => self::SECTION_TITLES['sala'], 'details' => $d, 'items' => array_merge($items, $themed['sala'])];
    }

    private static function wc(array $v): array
    {
        $h = is_array($v['habitation'] ?? null) ? $v['habitation'] : [];
        $shower = ! empty($h['shower_type']) ? (self::SHOWER[$h['shower_type']] ?? $h['shower_type']) : null;

        return ['id' => 'wc', 'title' => self::sectionTitle('wc', $v['vehicle_type'] ?? null), 'details' => self::rows([
            self::pair('Duche', $shower),
            self::pair('Água limpa', self::has($h['clean_water_litres'] ?? null) ? "{$h['clean_water_litres']} L" : null),
            self::pair('Águas residuais', self::has($h['waste_water_litres'] ?? null) ? "{$h['waste_water_litres']} L" : null),
        ]), 'items' => array_values(array_filter([
            self::truthy($h['has_toilet'] ?? null) ? 'Sanita' : null,
            self::truthy($h['has_shower'] ?? null) && ! $shower ? 'Duche' : null,
        ]))];
    }

    private static function cabin(array $v, array $themed): array
    {
        $f = is_array($v['features'] ?? null) ? $v['features'] : [];
        $d = [];
        if (! empty($f['chassis_type'])) {
            $d[] = ['label' => 'Chassis', 'value' => self::CHASSIS[$f['chassis_type']] ?? $f['chassis_type']];
        }
        if (! empty($f['chassis_notes']) && trim((string) $f['chassis_notes']) !== '') {
            $d[] = ['label' => 'Notas do chassis', 'value' => trim((string) $f['chassis_notes'])];
        }
        return ['id' => 'cabine', 'title' => self::sectionTitle('cabine', $v['vehicle_type'] ?? null), 'details' => $d, 'items' => $themed['cabine']];
    }

    private static function exterior(array $v, array $themed): array
    {
        $f = is_array($v['features'] ?? null) ? $v['features'] : [];
        $d = [];
        $extra = [];
        if (! empty($f['has_awning']) && ! empty($f['awning_brand'])) {
            $d[] = ['label' => 'Toldo', 'value' => $f['awning_brand']];
        }
        $garage = is_array($f['garage'] ?? null) ? $f['garage'] : [];
        if (! empty($garage['has_garage'])) {
            $bits = self::joinBits([
                ! empty($garage['has_double_opening']) ? 'abertura dos dois lados' : null,
                ! empty($garage['is_spacious']) ? 'espaçosa' : null,
                ! empty($garage['has_height_adjuster']) ? 'altura ajustável' : null,
            ]);
            if ($bits !== '') {
                $d[] = ['label' => 'Garagem', 'value' => $bits];
            } else {
                $extra[] = 'Garagem';
            }
        }
        $items = array_values(array_filter($themed['exterior'], fn ($l) => ! ($l === 'Toldo' && ! empty($f['awning_brand']))));

        return ['id' => 'exterior', 'title' => self::SECTION_TITLES['exterior'], 'details' => $d, 'items' => array_merge($items, $extra)];
    }

    private static function condition(array $v): array
    {
        $records = $v['service_records'] ?? null;

        return ['id' => 'condicao', 'title' => 'Condição',
            'details' => is_string($records) && trim($records) !== '' ? [['label' => 'Livro de revisões', 'value' => trim($records)]] : [],
            'items' => array_values(array_filter([
                ! empty($v['has_spare_key']) ? '2ª chave' : null,
                ! empty($v['has_manuals']) ? 'Manuais' : null,
                $records === true ? 'Livro de revisões' : null,
            ]))];
    }

    private static function others(array $themed): array
    {
        return ['id' => 'outros', 'title' => self::SECTION_TITLES['outros'], 'details' => [], 'items' => $themed['outros']];
    }
}
