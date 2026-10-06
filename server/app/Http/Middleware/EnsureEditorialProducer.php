<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Editorial\EditorialWorkflowService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Linha Editorial: ações de produção e de estrutura do calendário (abrir e fechar meses,
 * âncoras próprias, esconder e mostrar âncoras). No modo "Produção pela equipa XPLENDOR"
 * só a equipa as faz; o cliente comenta, aprova e pede alterações. Assume tenant antes.
 */
class EnsureEditorialProducer
{
    public function handle(Request $request, Closure $next): Response
    {
        EditorialWorkflowService::assertProducer($request->user(), (int) $request->route('id'));

        return $next($request);
    }
}
