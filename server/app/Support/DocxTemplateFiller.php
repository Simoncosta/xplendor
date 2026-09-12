<?php

declare(strict_types=1);

namespace App\Support;

use PhpOffice\PhpWord\TemplateProcessor;
use ZipArchive;

/**
 * DMS — Caminho B: preenche um .docx com {{ variáveis }} a partir de um mapa de
 * valores, e devolve o .docx preenchido + as variáveis NÃO reconhecidas.
 *
 * Estratégia PROVADA no spike:
 *  1. Pré-passo de regex que junta delimitadores {{ }} partidos por tags XML
 *     (o modo de falha #1 do .docx — o Word/Docs parte a variável em runs).
 *  2. Normaliza {{ → ${ e }} → } (sintaxe nativa do PHPWord).
 *  3. O TemplateProcessor corre `fixBrokenMacros` (repara o resto dos splits).
 *  4. getVariables() → substitui as conhecidas; as restantes são "não
 *     reconhecidas" (typo / variável inexistente) → devolvidas ao chamador.
 *
 * Sem LibreOffice, sem PDF no servidor.
 */
class DocxTemplateFiller
{
    /** Partes do .docx que podem conter variáveis. */
    private const PART_PATTERN = '/^word\/(document|header\d*|footer\d*)\.xml$/';

    /**
     * Preenche o .docx: para cada variável encontrada, o valor é decidido pelo
     * $resolve(var). Devolve as variáveis encontradas.
     *
     * @param  callable(string):string $resolve
     * @return array{path: string, found: array<int,string>}
     */
    public static function render(string $srcPath, string $outPath, callable $resolve): array
    {
        // Trabalha sobre uma cópia (não mexe no modelo original).
        copy($srcPath, $outPath);

        $zip = new ZipArchive();
        if ($zip->open($outPath) !== true) {
            throw new \RuntimeException('Não foi possível abrir o modelo .docx.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && preg_match(self::PART_PATTERN, $name)) {
                $xml = $zip->getFromName($name);
                if ($xml !== false) {
                    $zip->addFromString($name, self::normalizeXml($xml));
                }
            }
        }
        $zip->close();

        $processor = new TemplateProcessor($outPath);
        $found = $processor->getVariables();

        foreach ($found as $var) {
            $processor->setValue($var, $resolve($var));
        }

        $processor->saveAs($outPath);

        return ['path' => $outPath, 'found' => array_values($found)];
    }

    /**
     * Preenchimento simples (retro-compat): variáveis conhecidas → valor; as
     * restantes ficam como {{var}} literal. Devolve as não reconhecidas.
     *
     * @param  array<string,string> $values
     * @return array{path: string, missing: array<int,string>, found: array<int,string>}
     */
    public static function fill(string $srcPath, array $values, string $outPath): array
    {
        $res = self::render(
            $srcPath,
            $outPath,
            fn (string $var) => array_key_exists($var, $values) ? $values[$var] : '{{' . $var . '}}'
        );
        $missing = array_values(array_diff($res['found'], array_keys($values)));

        return ['path' => $outPath, 'missing' => $missing, 'found' => $res['found']];
    }

    /** Pré-passo (junta delimitadores partidos) + normaliza {{ }} → ${ }. */
    private static function normalizeXml(string $xml): string
    {
        // {  <tags>  {   →   <tags>{{   (delimitador de abertura partido)
        $xml = preg_replace('/\{((?:<[^>]+>)+)\{/', '$1{{', $xml);
        // }  <tags>  }   →   }}<tags>   (delimitador de fecho partido)
        $xml = preg_replace('/\}((?:<[^>]+>)+)\}/', '}}$1', $xml);
        // Normaliza para a sintaxe nativa do PHPWord.
        return str_replace(['{{', '}}'], ['${', '}'], $xml);
    }
}
