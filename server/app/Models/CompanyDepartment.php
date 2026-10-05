<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Departamento de uma empresa (ex.: Comercial, Oficina), com os contactos públicos dele. */
class CompanyDepartment extends Model
{
    public const SUGGESTED = ['Comercial', 'Oficina', 'Pós-Venda', 'Admin'];

    protected $fillable = ['company_id', 'name', 'sort', 'active', 'whatsapp', 'phone', 'phone_type', 'email'];

    protected $casts = ['active' => 'boolean', 'sort' => 'integer'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function collaborators(): HasMany
    {
        return $this->hasMany(Collaborator::class, 'department_id');
    }
}
