<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Financing\Antivirus\ClamdUnavailable;
use App\Services\Financing\Antivirus\VirusScanner;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert;

/**
 * O ClamAV simulado das Faturas e da caixa de correio: limpo (por omissão), infetado, em baixo ou sem
 * analisador configurado. Conta as análises e confirma que cada uma é feita a partir de um ficheiro.
 */
final class FakeVirusScanner implements VirusScanner
{
    public string $mode = 'clean';
    public int $calls = 0;

    public function configured(): bool
    {
        return $this->mode !== 'none';
    }

    public function ping(): bool
    {
        return $this->mode === 'clean' || $this->mode === 'infected';
    }

    public function scan(string $disk, string $path): string
    {
        $this->calls++;
        Assert::assertTrue(Storage::disk($disk)->exists($path), 'o ficheiro é analisado a partir do disco');

        return match ($this->mode) {
            'down' => throw new ClamdUnavailable('em baixo'),
            'none' => throw new \LogicException('Sem analisador antivírus configurado.'),
            'infected' => self::INFECTED,
            default => self::CLEAN,
        };
    }

    public function lastSignature(): ?string
    {
        return $this->mode === 'infected' ? 'Win.Test.EICAR_HDB-1' : null;
    }
}
