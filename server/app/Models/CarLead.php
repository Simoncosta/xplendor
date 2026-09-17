<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarLead extends Model
{
    use HasFactory;

    // Fases do funil (CRM). Ordem = fluxo Nova → … → Venda/Perdida. `spam` fica
    // fora do funil (não é coluna; marca-se na lista). `qualified` é mostrado
    // como "Proposta" na UI (fase intermédia já existente, reaproveitada).
    public const STATUSES = ['new', 'contacted', 'visit', 'qualified', 'negotiation', 'won', 'lost', 'spam'];

    // Colunas do Kanban do funil (exclui 'spam').
    public const FUNNEL_STAGES = ['new', 'contacted', 'visit', 'qualified', 'negotiation', 'won', 'lost'];

    // Motivos de perda (11). Chave guardada em `lost_reason`; rótulo é da UI.
    public const LOSS_REASONS = [
        'preco', 'financiamento', 'veiculo_inadequado', 'concorrencia', 'adiou',
        'sem_resposta', 'so_pesquisa', 'distancia', 'ja_comprou', 'retoma', 'outro',
    ];

    protected $fillable = [
        'name',
        'email',
        'phone',
        'message',
        'status',
        'assigned_user_id',
        'assigned_at',
        'contacted_at',
        'closed_at',
        'lost_reason',
        'notes',
        'source',
        'pending_until',
        'referrer',
        'landing_path',
        'channel',
        'visitor_id',
        'session_id',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'car_id',
        'company_id',
    ];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
