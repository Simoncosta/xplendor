<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Financing\Antivirus\AntivirusSamples;
use App\Services\Financing\Antivirus\ClamdClient;
use App\Services\Financing\Antivirus\VirusScanner;
use Illuminate\Console\Command;

/**
 * Confirma que o antivírus dos documentos de financiamento funciona: fala com o clamd, mostra a
 * versão das assinaturas e analisa três ficheiros gerados na hora: o EICAR (tem de dar infetado),
 * um PDF limpo (tem de dar limpo) e um PDF com o EICAR anexado (tem de dar infetado). Nada é
 * gravado. Sai com erro se alguma resposta não for a esperada.
 */
class AntivirusCheckCommand extends Command
{
    protected $signature = 'antivirus:check';

    protected $description = 'Verifica o ClamAV: ligação, versão das assinaturas, EICAR infetado e PDF limpo.';

    public function handle(): int
    {
        $driver = (string) config('financing.antivirus.driver');
        $host = (string) config('financing.antivirus.host');
        $port = (int) config('financing.antivirus.port');
        $this->line("Analisador configurado: {$driver} · clamd em {$host}:{$port}");
        if ($driver !== 'clamav') {
            $this->warn('ANTIVIRUS_DRIVER não é "clamav": a plataforma não está a usar o antivírus (a verificação abaixo fala com o clamd na mesma).');
        }
        $client = ClamdClient::fromConfig();
        if (! $client->ping()) {
            $this->error('O clamd não responde (está a arrancar, a descarregar as assinaturas ou parado). Veja docker ps e docker logs xplendor-clamav.');

            return self::FAILURE;
        }
        $this->info('PING: PONG');
        $this->line('Versão: ' . $client->version());

        $ok = true;
        foreach ([
            ['EICAR', AntivirusSamples::eicar(), VirusScanner::INFECTED],
            ['PDF limpo', AntivirusSamples::cleanPdf(), VirusScanner::CLEAN],
            ['PDF com o EICAR anexado', AntivirusSamples::eicarPdf(), VirusScanner::INFECTED],
        ] as [$label, $content, $expected]) {
            try {
                [$result, $signature] = $client->scanString($content);
            } catch (\Throwable $e) {
                $this->error("{$label}: erro ({$e->getMessage()})");
                $ok = false;

                continue;
            }
            $text = $result === VirusScanner::INFECTED ? "infetado ({$signature})" : 'limpo';
            if ($result === $expected) {
                $this->info("{$label}: {$text} · como esperado");
            } else {
                $this->error("{$label}: {$text} · ESPERADO " . ($expected === VirusScanner::INFECTED ? 'infetado' : 'limpo'));
                $ok = false;
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
