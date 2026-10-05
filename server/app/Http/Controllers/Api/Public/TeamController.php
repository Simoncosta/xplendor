<?php

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Collaborator;
use App\Models\CompanyDepartment;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Equipa para os sites dos clientes (GET /api/public/team?token=). Só a empresa do
 * token, só colaboradores ativos, marcados para o site e com autorização de publicação.
 * Contacto: o pessoal só com autorização própria; senão o do departamento. Nunca
 * devolve dados de conta (user_id, email da conta, perfil) nem dados internos.
 */
class TeamController extends Controller
{
    public function index(Request $request)
    {
        $company = $request->input('public_api_company');

        $departments = CompanyDepartment::where('company_id', $company->id)->where('active', true)
            ->orderBy('sort')->orderBy('name')->get()->keyBy('id');
        $members = Collaborator::publishable()->where('company_id', $company->id)
            ->orderBy('sort')->orderBy('name')->get();

        $groups = [];
        foreach ($departments as $d) {
            $groups[$d->id] = ['id' => $d->id, 'name' => $d->name, 'contact' => $this->contact($d->whatsapp, $d->phone, $d->phone_type, $d->email), 'members' => []];
        }
        $noDepartment = [];
        foreach ($members as $m) {
            $dept = $m->department_id ? ($departments[$m->department_id] ?? null) : null;
            $personal = $m->contact_mode === 'personal' && $m->personal_contact_consent_at !== null;
            $member = [
                'id'             => $m->id,
                'name'           => $m->name,
                'first_name'     => strtok($m->name, ' ') ?: $m->name,
                'role_title'     => $m->role_title,
                'bio'            => $m->bio,
                'photo_url'      => $m->photo_path ? Storage::disk('public')->url($m->photo_path) : null,
                'contact_source' => $personal ? 'personal' : ($dept ? 'department' : null),
                'contact'        => $personal
                    ? $this->contact($m->whatsapp, $m->phone, $m->phone_type, $m->email)
                    : ($dept ? $this->contact($dept->whatsapp, $dept->phone, $dept->phone_type, $dept->email) : null),
            ];
            if ($dept) {
                $groups[$dept->id]['members'][] = $member;
            } else {
                $noDepartment[] = $member;
            }
        }

        $data = array_values(array_filter($groups, fn ($g) => $g['members'] !== []));
        if ($noDepartment !== []) {
            $data[] = ['id' => null, 'name' => null, 'contact' => null, 'members' => $noDepartment];
        }

        return ApiResponse::success(['departments' => $data], 'Team fetched successfully.')
            ->header('Cache-Control', 'public, max-age=300');
    }

    /** Contacto público: número para wa.me, número para tel:, tipo (fixo mostra o aviso de custo) e email. */
    private function contact(?string $whatsapp, ?string $phone, ?string $phoneType, ?string $email): ?array
    {
        $c = [
            'whatsapp'      => PhoneNumber::internationalDigits($whatsapp),
            'phone'         => $phone ?: null,
            'phone_tel'     => PhoneNumber::tel($phone),
            'phone_type'    => $phone ? ($phoneType ?: 'mobile') : null,
            'email'         => $email ?: null,
        ];

        return array_filter($c, fn ($v) => $v !== null) === [] ? null : $c;
    }
}
