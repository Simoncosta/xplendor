<?php

declare(strict_types=1);

namespace App\Exceptions;

/** XPLENDOR — FB-1: as guardas do "Lançar no PingWin" que falham (resposta 422 com a lista). */
class LaunchBlocked extends \RuntimeException
{
    public function __construct(public readonly array $failed)
    {
        parent::__construct('Não é possível lançar: ' . collect($failed)->pluck('message')->implode(' '));
    }
}
