<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — FASE 0.5 (motor de autocaravanas): backfill do layout.
 *
 * Os snapshots anteriores à Fase 0 têm layout=NULL, mas quando o scrape foi
 * filtrado por body_type a tipologia ficou gravada em `category` (slug
 * canónico: perfiladas/integral/capucine/furgao). Sem este backfill, o pool
 * elegível do motor de similaridade arranca a ZERO (cold-start) até novos
 * scrapes — em dev recupera ~106/126 snapshots de imediato.
 *
 * Idempotente e portável (query builder — corre em MySQL e sqlite). NÃO toca
 * em snapshots com layout já preenchido nem em category fora do vocabulário
 * ('660' numérico do Standvirtual, vazios).
 */
return new class extends Migration
{
    private const CANONICAL_LAYOUTS = ['perfiladas', 'integral', 'capucine', 'furgao', 'caravana'];

    public function up(): void
    {
        DB::table('car_market_snapshots')
            ->where('vehicle_type', 'motorhome')
            ->whereNull('layout')
            ->whereIn('category', self::CANONICAL_LAYOUTS)
            ->update(['layout' => DB::raw('category')]);
    }

    public function down(): void
    {
        // Reverter apenas o que este backfill pode ter escrito (layout igual
        // à category canónica). Layouts vindos do scraper (Fase 0) têm origem
        // própria e não são distinguíveis — em down() limpamos o subconjunto
        // seguro: layout == category.
        DB::table('car_market_snapshots')
            ->where('vehicle_type', 'motorhome')
            ->whereIn('category', self::CANONICAL_LAYOUTS)
            ->whereColumn('layout', 'category')
            ->update(['layout' => null]);
    }
};
