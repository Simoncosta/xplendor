<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Os nomes dos clientes não se escrevem no código: as lojas, os armazéns e as rotas vêm da base de
 * dados ou da configuração da empresa. Ficam de fora os testes, as migrações (de dados) e as fixtures.
 */
class NoClientNamesInCodeTest extends TestCase
{
    private const PATTERN = '/yuko|tabern|costa\s+cabral/i';

    private const DIRS = ['app', 'config', 'routes', 'resources', 'database/seeders', 'database/factories', 'bootstrap/app.php'];

    public function test_no_client_name_is_written_in_the_server_code(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        foreach (self::DIRS as $dir) {
            $path = "{$root}/{$dir}";
            if (! file_exists($path)) {
                continue;
            }
            $files = is_file($path) ? [$path] : array_map(fn ($f) => $f->getPathname(), iterator_to_array(
                new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS))
            ));
            foreach ($files as $file) {
                if (! preg_match('/\.(php|json|js|ts|html|txt|md|yml|yaml)$/', $file) || str_contains($file, '/fixtures/')) {
                    continue;
                }
                foreach (file($file) as $i => $line) {
                    if (preg_match(self::PATTERN, $line)) {
                        $found[] = substr($file, strlen($root) + 1) . ':' . ($i + 1) . ': ' . trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $found, "Nomes de clientes no código do servidor:\n" . implode("\n", $found));
    }
}
