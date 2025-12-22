<?php

namespace App\Helpers;

use Illuminate\Http\Request;

class ApiPaginate
{
    public static function perPage(Request $request): int
    {
        return min(
            (int) $request->input('perPage', 15),
            100 // limite máximo
        );
    }
}
