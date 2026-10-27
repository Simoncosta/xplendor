<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Mailbox\OutgoingHtml;
use PHPUnit\Framework\TestCase;

/** E-mail 5 · O HTML do editor (Quill 2) antes de sair: as listas a sério, sem scripts nem imagens, e o texto simples. */
class OutgoingHtmlTest extends TestCase
{
    public function test_quill_2_lists_become_real_ul_and_ol_split_by_type(): void
    {
        $quill = '<p>Peças:</p><ol><li data-list="bullet"><span class="ql-ui" contenteditable="false"></span>Filtro</li>'
            . '<li data-list="bullet"><span class="ql-ui" contenteditable="false"></span>Óleo</li>'
            . '<li data-list="ordered"><span class="ql-ui" contenteditable="false"></span>Primeiro</li></ol>';
        $this->assertSame('<p>Peças:</p><ul><li>Filtro</li><li>Óleo</li></ul><ol><li>Primeiro</li></ol>', OutgoingHtml::clean($quill));
        $this->assertSame("Peças:\n\n- Filtro\n- Óleo\n\n- Primeiro", OutgoingHtml::toText(OutgoingHtml::clean($quill)));
    }

    public function test_only_text_and_links_are_kept(): void
    {
        $html = '<p onclick="x()">Olá <strong>Joana</strong> <a href="javascript:alert(1)">mau</a> <a href="https://exemplo.invalid">bom</a></p>'
            . '<script>alert(1)</script><img src="https://rastreio.invalid/p.gif"><iframe src="https://x.invalid"></iframe>';
        $clean = OutgoingHtml::clean($html);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('<img', $clean);
        $this->assertStringNotContainsString('<iframe', $clean);
        $this->assertStringContainsString('<a href="https://exemplo.invalid" target="_blank" rel="noreferrer noopener">bom</a>', $clean);
        $this->assertSame('Olá Joana mau bom (https://exemplo.invalid)', OutgoingHtml::toText($clean));
    }
}
