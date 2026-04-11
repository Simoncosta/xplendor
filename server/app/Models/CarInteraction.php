<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarInteraction extends Model
{
    use HasFactory;

    /**
     * -------------------------------------
     * Tipo de ação no interaction_type
     * -------------------------------------
     * whatsapp_click
     * call_click
     * show_phone
     * copy_phone
     * favorite
     * share
     * form_open
     * form_start
     * location_view
     */

    /**
     * -------------------------------------
     * interaction_target aju a responder "O utilizador clicou no WhatsApp de onde?"
     * -------------------------------------
     * car_card
     * car_detail
     * floating_button
     * header_button
     * footer_button
     * sticky_cta
     */

    /**
     * -------------------------------------
     * page_type útil para relatórios
     * -------------------------------------
     * home
     * listing
     * car_detail
     * company_page
     * contact_page
     * other
     */

    protected $fillable = [
        'interaction_type',
        'interaction_target',
        'car_id',
        'company_id',
        'user_id',

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

        'page_url',
        'page_type',
        'page_context',

        'phone',
        'whatsapp_number',

        'meta',

        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
