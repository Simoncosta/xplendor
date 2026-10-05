<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Models\Company;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — Orçamentos de serviços, LADO ADMIN (só a equipa XPLENDOR: root fora de
 * impersonation; em impersonation o token é do cliente). Vive no grupo /api/v1/admin
 * atrás do EnsureSuperAdmin; reconfirma root aqui (defesa em profundidade).
 */
class QuoteController extends Controller
{
    public function __construct(protected QuoteService $service) {}

    private function ensureRoot(): void
    {
        abort_unless(Auth::user()?->role === 'root', 403);
    }

    private function find(int $quoteId): Quote
    {
        $quote = Quote::find($quoteId);
        abort_unless($quote, 404, 'Orçamento não encontrado.');

        return $quote;
    }

    private function respond(Quote $quote, string $message)
    {
        $quote->loadMissing(['company:id,fiscal_name,trade_name', 'lines', 'versions']);

        return ApiResponse::success((new QuoteResource($quote))->resolve(), $message);
    }

    /** Empresas ativas da plataforma (ligação opcional para decidirem no painel delas). */
    public function companies()
    {
        $this->ensureRoot();

        $companies = Company::query()->active()->orderBy('fiscal_name')
            ->get(['id', 'fiscal_name', 'trade_name'])
            ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->trade_name ?: $c->fiscal_name])
            ->all();

        return ApiResponse::success($companies, 'Companies fetched successfully.');
    }

    /** Clientes da XPLENDOR (módulo Clientes da empresa da equipa). */
    public function customers(Request $request)
    {
        $this->ensureRoot();

        return ApiResponse::success($this->service->searchCustomers($request->user(), $request->input('search')), 'Customers fetched successfully.');
    }

    /** Cria um cliente da XPLENDOR (grava no módulo Clientes). */
    public function storeCustomer(Request $request)
    {
        $this->ensureRoot();
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        $customer = $this->service->createCustomer($request->user(), $data);

        return ApiResponse::success($customer->only(['id', 'name', 'email', 'phone', 'nif']), 'Cliente criado.', 201);
    }

    public function index(Request $request)
    {
        $this->ensureRoot();

        $query = Quote::query()->with('company:id,fiscal_name,trade_name');
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->input('search')) {
            $query->where(fn ($q) => $q->where('client_name', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%")
                ->orWhere('number', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"));
        }

        return ApiResponse::success(QuoteResource::collection($query->orderByDesc('id')->get())->resolve(), 'Quotes fetched successfully.');
    }

    /** Condições por omissão de um orçamento novo (contrato mínimo, forma de pagamento, texto do desconto). */
    public function defaults()
    {
        $this->ensureRoot();

        return ApiResponse::success($this->service->defaults(), 'Quote defaults fetched successfully.');
    }

    /** Dashboard root: em aberto, aceites no ano e desde sempre (mensal e único separados). */
    public function summary()
    {
        $this->ensureRoot();

        return ApiResponse::success($this->service->summary(), 'Quotes summary fetched successfully.');
    }

    public function store(QuoteRequest $request)
    {
        $this->ensureRoot();
        $quote = $this->service->createQuote($request->validated(), $request->user());

        return $this->respond($quote, 'Orçamento criado.')->setStatusCode(201);
    }

    public function show(int $quoteId)
    {
        $this->ensureRoot();

        return $this->respond($this->find($quoteId), 'Quote fetched successfully.');
    }

    /** Rascunho: altera. Enviado, recusado ou expirado: nova versão em rascunho. Aceite: 422. */
    public function update(QuoteRequest $request, int $quoteId)
    {
        $this->ensureRoot();
        $quote = $this->service->updateQuote($this->find($quoteId), $request->validated(), $request->user());

        return $this->respond($quote, 'Orçamento guardado.');
    }

    /** Marca como enviado (o PDF foi enviado ao cliente por fora). */
    public function send(Request $request, int $quoteId)
    {
        $this->ensureRoot();
        $quote = $this->service->send($this->find($quoteId), $request->user());

        return $this->respond($quote, 'Orçamento marcado como enviado.');
    }

    /** Regista a resposta do cliente (orçamentos não ligados a uma empresa). */
    public function decision(Request $request, int $quoteId)
    {
        $this->ensureRoot();
        $data = $request->validate(['decision' => ['required', 'in:accept,refuse']]);
        $quote = $this->service->decide($this->find($quoteId), $data['decision'] === 'accept', byCompany: false);

        return $this->respond($quote, $data['decision'] === 'accept' ? 'Orçamento aceite.' : 'Orçamento recusado.');
    }

    public function duplicate(Request $request, int $quoteId)
    {
        $this->ensureRoot();
        $copy = $this->service->duplicate($this->find($quoteId), $request->user());

        return $this->respond($copy, 'Orçamento duplicado.')->setStatusCode(201);
    }

    /** Pré-visualização do estado atual (rascunho incluído), sem guardar. */
    public function previewPdf(int $quoteId)
    {
        $this->ensureRoot();
        $quote = $this->find($quoteId);

        return $this->pdfResponse($this->service->previewPdf($quote), $quote->displayNumber() . '-v' . $quote->version . '-previsualizacao.pdf');
    }

    /** O PDF congelado de uma versão enviada (o que o cliente recebeu). */
    public function versionPdf(int $quoteId, int $version)
    {
        $this->ensureRoot();
        $v = $this->find($quoteId)->versions()->where('version', $version)->first();
        abort_unless($v, 404, 'Versão não encontrada.');

        return $this->pdfResponse($this->service->versionPdf($v), "{$v->number}-v{$v->version}.pdf");
    }

    public function destroy(int $quoteId)
    {
        $this->ensureRoot();
        $this->service->deleteQuote($this->find($quoteId));

        return ApiResponse::success(null, 'Orçamento apagado.');
    }

    private function pdfResponse(string $bytes, string $filename)
    {
        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }
}
