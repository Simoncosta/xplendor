<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Um pedido à IA, igual para os dois fornecedores: a instrução de sistema da função, o texto do
 * pedido, as imagens (base64; o modelo vê-as) e, quando a resposta é JSON, o esquema (saída
 * estruturada do fornecedor) ou só a indicação de JSON.
 */
final class AiPrompt
{
    /** @param array<int, array{media_type: string, data: string}> $images */
    public function __construct(
        public readonly string $system,
        public readonly string $text,
        public readonly array $images = [],
        public readonly ?array $schema = null,
        public readonly string $schemaName = 'resposta',
        public readonly bool $json = true,
    ) {}

    /** A partir das mensagens antigas ([system, user]) dos modos existentes. */
    public static function fromMessages(array $messages, ?array $schema = null, bool $json = true): self
    {
        $system = implode("\n\n", array_map(fn ($m) => (string) $m['content'], array_filter($messages, fn ($m) => ($m['role'] ?? '') === 'system')));
        $text = implode("\n\n", array_map(fn ($m) => (string) $m['content'], array_filter($messages, fn ($m) => ($m['role'] ?? '') !== 'system')));

        return new self($system, $text, [], $schema, 'resposta', $json);
    }

    public function withText(string $text): self
    {
        return new self($this->system, $text, $this->images, $this->schema, $this->schemaName, $this->json);
    }
}
