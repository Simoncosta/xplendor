<?php

namespace App\Observers;

use App\Models\Company;
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
