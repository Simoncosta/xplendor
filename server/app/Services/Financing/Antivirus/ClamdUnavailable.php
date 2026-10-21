<?php

declare(strict_types=1);

namespace App\Services\Financing\Antivirus;

/** O clamd não está a correr, está a arrancar ou não respondeu a tempo: a análise espera e tenta outra vez. */
final class ClamdUnavailable extends \RuntimeException
{
}
