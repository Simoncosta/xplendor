<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A central de contactos recebidos (dados pessoais; área ACL contactos, nunca a agência). Hoje só
 * o canal "site" (o site do consultor); preparada para os outros canais do roteiro (Standvirtual,
 * Meta, WhatsApp, telefone), sem tocar nos car_leads: cada canal grava com o seu source_ref (sem
 * duplicados) e o assunto (subject_type/subject_id: imóvel, empreendimento; mais tarde veículo).
 * Os consentimentos são separados (privacidade obrigatório no site; marketing opcional; partilha
 * com o parceiro de crédito só no pedido de crédito), com a versão da política aceite. Sem IP
 * gravado. O event_id é partilhado com o Pixel e a API de Conversões (F6).
 */
class InboundContact extends Model
{
    use SoftDeletes;

    public const TYPES = ['contacto' => 'Contacto', 'visita' => 'Pedido de visita', 'estudo_zona' => 'Estudo da zona', 'registo_procura' => 'Registo de procura',
        'avaliacao' => 'Pedido de avaliação', 'credito' => 'Pedido de crédito'];
    public const STATUSES = ['novo' => 'Novo', 'tratado' => 'Tratado', 'arquivado' => 'Arquivado'];
    public const CHANNELS = ['site' => 'Site', 'standvirtual' => 'Standvirtual', 'meta' => 'Meta', 'whatsapp' => 'WhatsApp', 'telefone' => 'Telefone', 'manual' => 'Manual'];
    public const SUBJECT_PROPERTY = 'property';
    public const SUBJECT_DEVELOPMENT = 'development';

    protected $fillable = ['company_id', 'channel', 'source_ref', 'consultant_site_id', 'type', 'subject_type', 'subject_id', 'name', 'email', 'phone', 'message', 'details',
        'consent_privacy_at', 'consent_marketing', 'consent_marketing_at', 'consent_credit_share', 'consent_credit_share_at', 'policy_version',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid', 'landing_path', 'page_path', 'referrer_host',
        'event_id', 'status', 'assigned_user_id', 'handled_at', 'retain_until'];

    protected $casts = ['details' => 'array', 'consent_privacy_at' => 'datetime', 'consent_marketing' => 'boolean', 'consent_marketing_at' => 'datetime',
        'consent_credit_share' => 'boolean', 'consent_credit_share_at' => 'datetime', 'handled_at' => 'datetime', 'retain_until' => 'date'];

    public function subjectProperty(): ?Property
    {
        return $this->subject_type === self::SUBJECT_PROPERTY && $this->subject_id ? Property::withTrashed()->find($this->subject_id) : null;
    }

    public function subjectDevelopment(): ?Development
    {
        return $this->subject_type === self::SUBJECT_DEVELOPMENT && $this->subject_id ? Development::withTrashed()->find($this->subject_id) : null;
    }
}
