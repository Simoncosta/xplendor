<?php

namespace App\Observers;

use App\Models\Company;
use App\Services\CompanyModuleService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CompanyObserver
{
    /**
     * Handle the Company "creating" event.
     */
    public function creating(Company $company): void
    {
        Log::info("Observer disparado", ['nome' => $company->fiscal_name]);

        if (empty($company->slug)) {
            $company->slug = $this->generateUniqueSlug($company->fiscal_name);
        }
    }

    /**
     * Nova empresa → preset AUTOMOTIVO por defeito (todos os módulos), para
     * nunca ficar sem módulos (o esconder do incremento 2 tornaria uma empresa
     * sem módulos inacessível). O super-admin ajusta depois.
     */
    public function created(Company $company): void
    {
        app(CompanyModuleService::class)->applyPreset($company->id, 'automotive');
    }

    protected function generateUniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;

        while (Company::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
