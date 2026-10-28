<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Arr;

/**
 * Pedidos com ficheiros (multipart): o browser envia os booleanos como texto, e "true"/"false"
 * não passam na regra boolean do Laravel (só "1"/"0", 1/0 e true/false). Antes da validação, passa
 * "true"/"false" (sem distinguir maiúsculas) a true/false, só nos campos cuja regra tem boolean,
 * também os encaixados com * (ex.: vehicle_attributes.exterior.has_awning). O resto não se toca.
 */
trait AcceptsTextBooleans
{
    protected function acceptTextBooleans(): void
    {
        $patterns = [];
        foreach ($this->rules() as $field => $rule) {
            $parts = is_array($rule) ? array_filter($rule, 'is_string') : explode('|', (string) $rule);
            if (in_array('boolean', array_map('trim', $parts), true)) {
                $patterns[] = '/^' . str_replace('\*', '[^.]+', preg_quote((string) $field, '/')) . '$/';
            }
        }
        if ($patterns === []) {
            return;
        }

        $input = $this->input();
        $touched = [];
        foreach (Arr::dot($input) as $key => $value) {
            if (! is_string($value) || ! in_array(strtolower($value), ['true', 'false'], true)) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, (string) $key)) {
                    Arr::set($input, (string) $key, strtolower($value) === 'true');
                    $touched[explode('.', (string) $key)[0]] = true;
                    break;
                }
            }
        }
        if ($touched !== []) {
            $this->merge(array_intersect_key($input, $touched));
        }
    }
}
