# CarPublicResource — Shape Reference

> **Breaking change document.**  
> Deployed: 2026-05-22 · `CarPublicResource` replaces the raw Car model in all `/api/public/cars` responses.  
> Affects: `/api/public/cars` (index), `/api/public/cars/{id}` (show).

---

## Breaking Changes vs. Previous Response

The previous response returned the raw Eloquent Car model, including sensitive fields.  
This version returns a curated, stable payload.

| Change | Before | After |
|---|---|---|
| `vin` | Exposed | **Removed** |
| `license_plate` | Exposed | **Removed** |
| `internal_notes` | Exposed | **Removed** |
| `seller.email` / `seller.role` / `seller.birthdate` | Exposed | **Removed** |
| `is_resume` | Present | **Renamed → `is_trade_in`** |
| `vehicle_attributes` (raw JSON) | Present as nested JSON | **Removed** — flattened into `specs`, `habitation`, `features`, `beds` |
| `seller` (full User relation) | Exposed | **Replaced by safe `seller` object** (name, avatar, mobile, whatsapp only) |
| `views_count`, `leads_count` | Appended via analytics | **Removed** (not for public) |
| `status` filter | Not enforced | **Enforced: `active`, `sold`, `available_soon`, `reserved`** (single source: `CarPublicRepository::PUBLIC_STATUSES`) |

---

## Full Payload Shape

```json
{
  "id": 55,
  "created_at": "2024-03-10T11:23:00.000000Z",

  "title": "Knaus Van Ti 550 MF",
  "brand": { "id": 12, "name": "Knaus" },
  "model": { "id": 34, "name": "Van Ti 550 MF" },
  "category": { "id": 8, "name": "Campervan", "slug": "campervan" },

  "vehicle_type": "motorhome",
  "subsegment": "autocaravana",
  "condition": "used",
  "status": "active",

  "price_gross": 45900.00,
  "price_promo": null,
  "has_promo_price": false,
  "hide_price_online": false,
  "monthly_payment": null,

  "year": 2019,
  "registration_month": 6,
  "mileage_km": 42000,
  "fuel_type": "diesel",
  "transmission": "manual",
  "doors": null,
  "power_hp": 140,
  "engine_capacity_cc": 1995,
  "cylinders": 4,
  "co2_emissions": null,
  "toll_class": null,

  "exterior_color": "Branco",
  "is_metallic": false,
  "interior_color": null,

  "origin": "national",
  "is_trade_in": false,
  "warranty_available": null,
  "warranty_due_date": null,
  "warranty_km": null,
  "service_records": null,
  "has_spare_key": false,
  "has_manuals": true,

  "specs": {
    "seats": 4,
    "length_m": 5.99,
    "width_m": 2.10,
    "height_m": 2.78,
    "gross_weight_kg": 3500,
    "autonomy_km": 800
  },

  "habitation": {
    "has_kitchen": true,
    "has_bathroom": true,
    "has_stove": true,
    "has_oven": false,
    "has_microwave": false,
    "has_extractor": true,
    "has_fridge": true,
    "fridge_litres": 90,
    "fridge_type": "compressor",
    "has_toilet": true,
    "has_shower": true,
    "shower_type": "independent",  // 'separate' | 'independent' | 'combined' | null
    "clean_water_litres": 100,
    "waste_water_litres": 90
  },

  "features": {
    "has_solar_panel": true,
    "has_inverter": false,
    "has_gpl": false,
    "has_generator": false,
    "has_external_power_socket": true,
    "water_heater_source": "electric",
    "ambient_heating_source": "diesel",
    "ambient_heating_brand": "Truma",
    "battery_count": 1,
    "has_awning": true,
    "awning_brand": "Fiamma",
    "has_bike_rack": false,
    "has_motorbike_rack": false,
    "has_electric_step": true,
    "has_manual_step": false,
    "has_stabilizers": false,
    "has_spare_wheel": false,
    "has_bull_eye": false,
    "has_external_wc": false,
    "has_hubcaps": false,
    "has_national_antenna": false,
    "has_parabolic_antenna": false,
    "has_remifront": false,
    "has_window_blackouts": true,
    "has_mosquito_nets": true,
    "has_door_mosquito_net": true,
    "has_cabin_blackouts": true,
    "has_turbovent_skylight": true,
    "has_panoramic_skylight": false,
    "has_40x40_skylight": false,
    "chassis_type": "standard",
    "has_alko_chassis": false,
    "has_foldable_table": true,
    "has_rotating_seats": false,
    "upholstery_state": "good",
    "has_curtains": true,
    "has_led_lighting": true,
    "has_halo_lighting": false,
    "has_tv_support": true,
    "has_tv": false,
    "has_command_panel": true,
    "has_alarm": false,
    "has_hatch_lock": false,
    "has_cabin_lock": false,
    "has_safe_door": false,
    "has_gas_lock": false,
    "has_entry_door_lock": true
  },

  "beds": [
    { "type": "cama_garagem", "label": "Cama de garagem" },
    { "type": "cama_central", "label": "Cama central" }
  ],

  "description_pt": "Autocaravana em excelente estado ...",
  "description_en": null,
  "youtube_url": null,

  "extras": [
    { "group": "comfort_multimedia", "items": ["Cruise Control", "Câmara de Marcha Atrás"] },
    { "group": "exterior_equipment", "items": [] },
    { "group": "interior_equipment", "items": [] },
    { "group": "safety_performance", "items": [] }
  ],

  "images": [
    { "url": "https://...", "is_primary": true, "order": 1 }
  ],
  "external_images": [
    { "url": "https://..." }
  ],

  "seller": {
    "name": "João Silva",
    "avatar": null,
    "mobile": "+351912345678",
    "whatsapp": "+351912345678"
  }
}
```

---

## Notes on Nullable Sections

| Field | Null when |
|---|---|
| `habitation` | `vehicle_type` is `car` or `motorcycle` |
| `features` | `vehicle_type` is `car` or `motorcycle` |
| `beds` | `vehicle_type` is `car` or `motorcycle` |
| `category` | Not a `motorhome`, or no category assigned |
| `seller` | No seller user assigned and no company admin found |

`specs` is always present (at minimum `{ "seats": N }`).  
Only keys with explicit non-null values are included in `habitation`, `features`, and `specs`.

---

## `hide_price_online` — "Sob consulta"

When `hide_price_online: true`, the stand has marked the price as **"Sob consulta"** (price on request). The external site **MUST NOT** show the numeric price (`price_gross` / `price_promo`) — display the literal label **`Sob consulta`** instead, regardless of what `price_gross` contains. The raw values are still emitted in the payload (for internal/admin consumers) but should be ignored by the public rendering.

This is the canonical signal for "preço sob consulta" and replaces any ad-hoc handling of empty/zero prices.

---

## Status Values in the Response

The `status` field is emitted raw. Public endpoints only return cars whose status is one of `active`, `sold`, `available_soon`, `reserved` (single source: `CarPublicRepository::PUBLIC_STATUSES`). The `draft` and `inactive` statuses are never exposed.

The external site decides how to render each status:

| Status | Meaning | Suggested rendering |
|---|---|---|
| `active` | On sale | Normal listing + CTA |
| `available_soon` | Coming soon | "Brevemente" badge |
| `sold` | Sold | "Vendido" badge, CTA off |
| `reserved` | Reserved (deposit/hold) | "Reservado" badge, alternative CTA |

---

## Approved Bed Type Slugs

| Slug | Label (pt-PT) |
|---|---|
| `camas_gemeas` | Camas gémeas |
| `cama_central` | Cama central |
| `cama_francesa` | Cama francesa |
| `cama_basculante` | Cama basculante |
| `cama_capucino` | Cama capucino |
| `cama_garagem` | Cama de garagem |
| `beliche` | Beliche |
| `cama_transversal` | Cama transversal |
| `cama_elevatoria_eletrica` | Cama elevatória eléctrica |
| `cama_suspensa` | Cama suspensa |
| `cama_convertivel` | Cama convertível de mesa |
| `outra` | Outra |
| `cama_rebativel_cabine` | Rebatível na cabine *(legacy — only shown when already stored)* |

---

## `/api/public/car-filters` Extended Shape

New fields added alongside existing keys:

```json
{
  "categories": [
    { "id": 8, "name": "Campervan", "slug": "campervan", "count": 3 }
  ],
  "bed_types": [
    { "slug": "cama_garagem", "label": "Cama de garagem", "count": 4 },
    { "slug": "cama_central", "label": "Cama central", "count": 2 }
  ],
  "seats_range": { "min": 2, "max": 9 },
  "length_m_range": { "min": 5.8, "max": 7.3 },
  "available_features": [
    { "key": "has_bathroom", "count": 4 },
    { "key": "has_kitchen", "count": 4 },
    { "key": "has_solar_panel", "count": 1 }
  ]
}
```

---

## New Query Filters — `/api/public/cars`

| Parameter | Type | Description |
|---|---|---|
| `category` | `string` | Category slug (e.g. `campervan`) |
| `bed_types[]` | `string[]` | One or more bed type slugs |
| `min_seats` | `integer` | Minimum seat count |
| `max_seats` | `integer` | Maximum seat count |
| `min_length_m` | `float` | Minimum vehicle length in metres |
| `max_length_m` | `float` | Maximum vehicle length in metres |
| `has_bathroom` | `boolean` | Must have bathroom |
| `has_kitchen` | `boolean` | Must have kitchen |
| `has_solar_panel` | `boolean` | Must have solar panel |

All existing filters (`condition`, `min_price_gross`, `fuel_types`, etc.) remain unchanged.
