<?php

/*
 * Dados legais da XPLENDOR (entidade, NIF, morada, email, redes sociais).
 * Fonte única em config/legal-company.json, partilhada com o site (páginas legais
 * e rodapé), para que uma correção num sítio chegue aos dois.
 */

$data = json_decode((string) file_get_contents(__DIR__ . '/legal-company.json'), true) ?: [];
unset($data['_comment']);

return $data;
