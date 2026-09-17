<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CarLead;
use Illuminate\Support\Collection;

/**
 * XPLENDOR — Match de leads por CONTACTO. A lead NÃO liga ao cliente por
 * customer_id (só tem contacto inline), por isso o match é por email/telefone.
 * Fonte ÚNICA reutilizada pela Fase 2 (vender→mover-lead) e pela Fase 3 (ficha-hub).
 */
class LeadMatchService
{
    // Estados "abertos" do funil (nem Venda/Perdida/Spam).
    public const OPEN_STATES = ['new', 'contacted', 'visit', 'qualified', 'negotiation'];

    /**
     * Leads da empresa que casam com o contacto dado (email igual, ci; OU telefone
     * com os últimos 9 dígitos iguais — tolera indicativo). `$onlyOpen` limita às
     * fases abertas. Devolve uma Collection<CarLead>.
     */
    public function byContact(int $companyId, ?string $email, ?string $phone, bool $onlyOpen = false): Collection
    {
        $email = $email ? mb_strtolower(trim($email)) : null;
        $phone = $phone ? preg_replace('/\D/', '', $phone) : null;

        if (! $email && ! $phone) {
            return collect();
        }

        $query = CarLead::query()->where('company_id', $companyId);
        if ($onlyOpen) {
            $query->whereIn('status', self::OPEN_STATES);
        }

        // Poucas leads por empresa → filtra em PHP (robusto a formatos de telefone).
        return $query->get()->filter(function (CarLead $lead) use ($email, $phone) {
            $leadEmail = $lead->email ? mb_strtolower(trim($lead->email)) : null;
            $leadPhone = $lead->phone ? preg_replace('/\D/', '', $lead->phone) : null;

            return ($email && $leadEmail && $leadEmail === $email)
                || $this->phonesMatch($phone, $leadPhone);
        })->values();
    }

    /**
     * Telefones já normalizados (só dígitos). Com ≥9 dígitos compara os últimos 9
     * (nº nacional PT), tolerando indicativo; senão exige igualdade.
     */
    public function phonesMatch(?string $a, ?string $b): bool
    {
        if (! $a || ! $b) {
            return false;
        }
        if (strlen($a) >= 9 && strlen($b) >= 9) {
            return substr($a, -9) === substr($b, -9);
        }

        return $a === $b;
    }
}
