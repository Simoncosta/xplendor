<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Repositories\Contracts\CompanyRepositoryInterface;

class CompanyService extends BaseService
{
    public function __construct(
        protected CompanyRepositoryInterface $companyRepository,
    ) {
        parent::__construct($companyRepository);
    }

    public function store($data): mixed
    {
        $subscriptionData = $this->buildInitialSubscriptionData();

        // cria empresa sem logo (ou com campos normais)
        $company = $this->companyRepository->store([
            ...$data,
            ...$subscriptionData,
        ]);

        // se veio logo, faz upload e atualiza
        if (!empty($data['logo']) && $data['logo'] instanceof UploadedFile) {
            $logoPath = $this->storeOrReplaceLogo($company->id, $data['logo'], null);

            $company = $this->companyRepository->update($company->id, [
                'logo_path' => $logoPath,
            ]);
        }

        return $company;
    }

    private function buildInitialSubscriptionData(): array
    {
        $company = new Company();
        $company->initializeTrial();

        return [
            'subscription_status' => $company->subscription_status,
            'trial_starts_at' => $company->trial_starts_at,
            'trial_ends_at' => $company->trial_ends_at,
            'subscription_ends_at' => $company->subscription_ends_at,
        ];
    }

    public function update(int $id, array $data): mixed
    {
        $company = $this->companyRepository->findOrFail($id, 'id');

        // Se veio logo, substitui e mete no payload
        if (!empty($data['logo']) && $data['logo'] instanceof UploadedFile) {
            $data['logo_path'] = $this->storeOrReplaceLogo(
                $company->id,
                $data['logo'],
                $company->logo_path
            );

            // não deixes passar o ficheiro para o update
            unset($data['logo']);
        }


        return $this->companyRepository->update($id, $data);
    }

    /**
     * Guarda o logo e apaga o anterior (se existir).
     * Retorna o caminho/URL a gravar no DB.
     */
    private function storeOrReplaceLogo(int $companyId, UploadedFile $file, ?string $oldLogoPath): string
    {
        $disk = Storage::disk('public');

        $folder = "company_{$companyId}/logo";
        $disk->makeDirectory($folder);

        $filename = "logo.webp";
        $path = "{$folder}/{$filename}";

        // Apagar antigo se existir
        if ($disk->exists($path)) {
            $disk->delete($path);
        }

        // Converter para WebP (igual às viaturas)
        $manager = new \Intervention\Image\ImageManager(
            new \Intervention\Image\Drivers\Gd\Driver()
        );

        $converted = $manager
            ->read($file)
            ->toWebp(85)
            ->toString();

        $disk->put($path, $converted);

        return Storage::url($path); // /storage/company_X/logo/logo.webp
    }
}
