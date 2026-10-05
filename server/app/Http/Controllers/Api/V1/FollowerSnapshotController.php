<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\SocialFollowerSnapshot;
use App\Services\CollaboratorService;
use App\Services\Social\FollowerSnapshotService;
use App\Services\Social\SocialConnectionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Seguidores (Perfil da Marca): ver o estado e o crescimento; registar manualmente
 * os seguidores atuais. Ver: qualquer utilizador da empresa (o tenant já validou o
 * {id}). Registar: administrador da empresa e equipa XPLENDOR (também em impersonation).
 */
class FollowerSnapshotController extends Controller
{
    public function __construct(
        private readonly FollowerSnapshotService $followers,
        private readonly SocialConnectionService $social,
    ) {}

    public function index(Request $request, int $companyId)
    {
        $days = (int) $request->query('days', FollowerSnapshotService::DEFAULT_DAYS);

        return ApiResponse::success(
            $this->followers->overview($companyId, $days)
                + ['can_record' => CollaboratorService::canEditContent($request->user(), $companyId), 'automation' => $this->social->automation($companyId)],
            'Seguidores carregados.'
        );
    }

    public function store(Request $request, int $companyId)
    {
        if (! CollaboratorService::canEditContent($request->user(), $companyId)) {
            return ApiResponse::error('Só o administrador da empresa pode registar os seguidores.', 403);
        }

        $data = $request->validate([
            'platform'        => ['required', Rule::in(SocialFollowerSnapshot::PLATFORMS)],
            'followers_count' => ['required', 'integer', 'min:0', 'max:2000000000'],
        ], [
            'followers_count.required' => 'Indique o número de seguidores.',
            'followers_count.integer'  => 'O número de seguidores tem de ser um número inteiro.',
            'followers_count.min'      => 'O número de seguidores não pode ser negativo.',
        ]);

        // Com a leitura automática a funcionar, o registo manual não está disponível.
        $automation = $this->social->automation($companyId)[$data['platform']] ?? ['connected' => false];
        if (! SocialConnectionService::manualEntry($automation)['manual_allowed']) {
            return ApiResponse::error('Esta rede é lida automaticamente: o registo manual só fica disponível se a leitura deixar de funcionar.', 409);
        }

        $this->followers->recordManual($companyId, $data['platform'], (int) $data['followers_count'], $request->user());

        return ApiResponse::success(
            $this->followers->overview($companyId) + ['can_record' => true, 'automation' => $this->social->automation($companyId)],
            'Seguidores registados.'
        );
    }
}
