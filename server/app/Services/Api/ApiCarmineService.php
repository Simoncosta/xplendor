<?php

namespace App\Services\Api;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiCarmineService
{
    protected string $baseUrl = "https://ws.easydata.pt/v1";
    public function __construct(
        protected string $dealerId,
        protected string $token,
    ) {}

    public function getListaDetalhesViatura()
    {
        $url = "{$this->baseUrl}/easydata/carros/GetListaDetalhesViatura/";

        try {

            /** @var Response $response */
            $response = Http::withHeaders([
                'Token' => $this->token,
                'Accept' => 'application/json',
            ])
                ->timeout(10)
                ->retry(3, 500)
                ->get($url, [
                    'dealer_id' => $this->dealerId,
                ]);

            // Lança exceção se status != 2xx
            $response->throw();

            return $response->json();
        } catch (\Throwable $e) {

            // Log detalhado do erro
            Log::channel('easydata')->error('EasyData API error', [
                'dealer_id' => $this->dealerId,
                'url' => $url,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            // Re-lança para o controller tratar (ou retorna null)
            throw new \Exception('Erro ao comunicar com EasyData');
        }
    }
}
