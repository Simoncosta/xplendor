<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Car;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A quem se avisa de um pedido sobre uma viatura: o vendedor da viatura (cars.seller_user_id,
 * ativo e da mesma empresa); sem vendedor, os administradores da empresa. E o nome da viatura
 * para os avisos (marca, modelo, versão e referência), sem dados do cliente.
 */
final class LeadRecipients
{
    /** @return Collection<int, User> */
    public static function sellerOrAdmins(Car $car): Collection
    {
        if ($car->seller_user_id) {
            $seller = User::where('id', $car->seller_user_id)->where('company_id', $car->company_id)
                ->whereNull('deactivated_at')->whereNotNull('email')->first();
            if ($seller) {
                return collect([$seller]);
            }
        }

        return self::admins((int) $car->company_id);
    }

    /** @return Collection<int, User> */
    public static function admins(int $companyId): Collection
    {
        return User::where('company_id', $companyId)->where('role', 'admin')->whereNull('deactivated_at')->whereNotNull('email')->orderBy('id')->get();
    }

    public static function carLabel(?Car $car): string
    {
        if (! $car) {
            return 'viatura';
        }
        $car->loadMissing(['brand:id,name', 'model:id,name']);
        $name = trim(implode(' ', array_filter([$car->brand?->name, $car->model?->name, $car->version])));

        return ($name !== '' ? $name : 'Viatura') . " (Ref. {$car->id})";
    }
}
