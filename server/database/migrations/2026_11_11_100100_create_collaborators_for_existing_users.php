<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cada utilizador que já existe (exceto a equipa XPLENDOR, role root) passa a ter um
 * colaborador ligado, com o nome e os contactos que tinha. Fica ESCONDIDO do site e
 * sem autorização de publicação: nada aparece sem ação da empresa. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        $users = DB::table('users')->whereNull('deleted_at')->where('role', '!=', 'root')->whereNotNull('company_id')
            ->whereNotIn('id', DB::table('collaborators')->whereNotNull('user_id')->select('user_id'))
            ->orderBy('id')->get(['id', 'company_id', 'name', 'mobile', 'whatsapp']);

        foreach ($users as $u) {
            DB::table('collaborators')->insert([
                'company_id'   => $u->company_id,
                'user_id'      => $u->id,
                'name'         => $u->name,
                'whatsapp'     => $u->whatsapp,
                'phone'        => $u->mobile,
                'phone_type'   => $u->mobile ? 'mobile' : null,
                'contact_mode' => 'department',
                'show_on_site' => false,
                'active'       => true,
                'sort'         => 0,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Os colaboradores criados aqui não têm dados próprios além dos do utilizador.
    }
};
