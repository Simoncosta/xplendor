<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Models\CarLead;
use App\Models\Customer;
use App\Models\MailMessage;
use App\Models\SatisfactionReport;

/**
 * E-mail 4 · A secção "Conversa": as mensagens guardadas porque se ligam a um cliente (as enviadas
 * pela XPLENDOR, as respostas ligadas pelo fio e as ligadas à mão). Na ficha do cliente com a
 * permissão da ficha; na viatura vendida com automovel.ver. O root fora da própria empresa e a
 * impersonation nunca veem o conteúdo (RoutePermissions::MAIL_CONTENT e rotas sensíveis).
 *   GET /companies/{id}/customers/{customer}/mail-messages
 *   GET /companies/{id}/cars/{carId}/mail-messages
 *   GET /companies/{id}/leads/{lead}/mail-messages   (E-mail 9: com a permissão de ver a lead)
 */
class MailConversationController extends Controller
{
    public function customer(int $companyId, int $customer)
    {
        $c = Customer::where('company_id', $companyId)->find($customer);
        if (! $c) {
            return ApiResponse::error('Cliente não encontrado.', 404);
        }

        return $this->list(MailMessage::where('company_id', $companyId)->where('customer_id', $c->id));
    }

    public function car(int $companyId, int $carId)
    {
        $car = Car::with('sale')->where('company_id', $companyId)->find($carId);
        if (! $car) {
            return ApiResponse::error('Viatura não encontrada.', 404);
        }
        $saleId = $car->sale?->id;
        $reports = $saleId ? SatisfactionReport::where('company_id', $companyId)->where('car_sale_id', $saleId)->pluck('id') : collect();
        if (! $saleId) {
            return ApiResponse::success(['messages' => []]);
        }

        return $this->list(MailMessage::where('company_id', $companyId)
            ->where(fn ($q) => $q->where('car_sale_id', $saleId)->orWhereIn('satisfaction_report_id', $reports)));
    }

    /** E-mail 9: a "Conversa" da lead (o link de financiamento e o de retoma enviados pela caixa, e o resto ligado à lead). */
    public function lead(int $companyId, int $lead)
    {
        $l = CarLead::where('company_id', $companyId)->find($lead);
        if (! $l) {
            return ApiResponse::error('Lead não encontrada.', 404);
        }

        return $this->list(MailMessage::where('company_id', $companyId)->where('car_lead_id', $l->id));
    }

    private function list($query)
    {
        $messages = $query->orderByRaw('COALESCE(received_at, sent_at, created_at) asc')->limit(200)->get();

        return ApiResponse::success(['messages' => $messages->map(fn (MailMessage $m) => $m->toConversationArray())->values()]);
    }
}
