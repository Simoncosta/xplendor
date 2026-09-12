<?php

namespace App\Services;

use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;

class CustomerService extends BaseService
{
    public function __construct(protected CustomerRepositoryInterface $customerRepository)
    {
        parent::__construct($customerRepository);
    }

    /**
     * RGPD — consentimento CENTRALIZADO no Cliente, aplicado de forma consistente
     * em store E update (o mesmo helper): sem contact_consent, a PII de CONTACTO
     * (phone/email) NÃO é gravada. name/nif/morada/CC têm base contratual (não são
     * gated por consentimento — são necessários para os documentos de venda).
     *
     * Só aplica quando `contact_consent` vem no payload (permite updates parciais
     * que não mexem no consentimento).
     */
    private function applyConsentGate(array $data): array
    {
        if (! array_key_exists('contact_consent', $data)) {
            return $data;
        }

        if (! (bool) $data['contact_consent']) {
            $data['phone'] = null;
            $data['email'] = null;
        }

        return $data;
    }

    public function store(array $data): mixed
    {
        return $this->customerRepository->store($this->applyConsentGate($data));
    }

    public function update(int $id, array $data): mixed
    {
        return $this->customerRepository->update($id, $this->applyConsentGate($data));
    }

    /**
     * Regra de eliminação (mesma filosofia do Supplier):
     *  - SEM vendas associadas → hard delete.
     *  - COM vendas → NÃO elimina; devolve false (controller responde 422 →
     *    a UI arquiva, preservando o histórico das vendas que o referenciam).
     */
    public function deleteOrBlock(Customer $customer): bool
    {
        if ($customer->sales()->exists()) {
            return false;
        }

        $this->customerRepository->destroy($customer->id);

        return true;
    }
}
