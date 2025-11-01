<?php

namespace App\Observers;

use App\Models\Company;
use Illuminate\Support\Facades\Auth;

class CompanyObserver
{
    /**
     * Handle the Company "created" event.
     */
    public function created(Company $company): void
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user && is_null($user->company_id)) {
            $user->company_id = $company->id;
            $user->save();
        }
    }

    /**
     * Handle the Company "updated" event.
     */
    public function updated(Company $company): void
    {
        //
    }

    /**
     * Handle the Company "deleted" event.
     */
    public function deleted(Company $company): void
    {
        //
    }

    /**
     * Handle the Company "restored" event.
     */
    public function restored(Company $company): void
    {
        //
    }

    /**
     * Handle the Company "force deleted" event.
     */
    public function forceDeleted(Company $company): void
    {
        //
    }
}
