import { test } from "node:test";
import assert from "node:assert/strict";
import { firstSentence, shortParish } from "../lib/home.ts";
import { thousands } from "../lib/format.ts";

test("Quem sou leva só a primeira frase da biografia", () => {
    assert.equal(firstSentence(null), null);
    assert.equal(firstSentence("   "), null);
    assert.equal(firstSentence("Trabalho no Porto. Antes, fui fotógrafo."), "Trabalho no Porto.");
    assert.equal(firstSentence("Sem ponto final"), "Sem ponto final");
    assert.equal(firstSentence("Licença n.º 123 e mais.").length > 0, true);
});

test("o nome curto de uma união de freguesias", () => {
    assert.equal(shortParish("União das freguesias de Aldoar, Foz do Douro e Nevogilde"), "Aldoar, Foz do Douro e Nevogilde");
    assert.equal(shortParish("Ramalde"), "Ramalde");
});

test("os milhares com um espaço que não parte", () => {
    assert.equal(thousands(3293), "3 293");
    assert.equal(thousands(950), "950");
    assert.equal(thousands(1234567), "1 234 567");
});

test("a mensagem do WhatsApp da ficha leva a referência e o número só com algarismos", async () => {
    const { fillMessage, whatsappHref } = await import("../lib/whatsapp.ts");
    const text = fillMessage("Olá {nome}, gostava de saber mais sobre o imóvel com a referência {referencia}.", { nome: "Ana", referencia: "EX-001" });
    assert.equal(text, "Olá Ana, gostava de saber mais sobre o imóvel com a referência EX-001.");
    assert.equal(whatsappHref("+351 900 000 000", "Olá"), "https://wa.me/351900000000?text=Ol%C3%A1");
});

test("as áreas com vírgula decimal e sem zeros à direita", async () => {
    const { areaM2 } = await import("../lib/format.ts");
    assert.equal(areaM2(92.1), "92,1 m²");
    assert.equal(areaM2(92), "92 m²");
    assert.equal(areaM2(1250.55), "1 250,55 m²");
    assert.equal(areaM2(null), null);
});
