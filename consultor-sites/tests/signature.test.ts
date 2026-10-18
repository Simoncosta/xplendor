import { test } from "node:test";
import assert from "node:assert/strict";
import { createHmac } from "node:crypto";
import { verifySignature } from "../lib/signature.ts";

const secret = "segredo-de-teste";
const body = JSON.stringify({ hosts: ["consultor.exemplo.test"], tags: ["site"] });
const sign = (ts: string, b = body) => createHmac("sha256", secret).update(`${ts}.${b}`).digest("hex");

test("aceita a assinatura certa dentro de 5 minutos", () => {
    assert.equal(verifySignature(secret, "1000", body, sign("1000"), 1100), true);
});

test("recusa assinatura errada, corpo alterado, carimbo velho e segredo vazio", () => {
    assert.equal(verifySignature(secret, "1000", body, "x".repeat(64), 1000), false);
    assert.equal(verifySignature(secret, "1000", body + " ", sign("1000"), 1000), false);
    assert.equal(verifySignature(secret, "1000", body, sign("1000"), 1000 + 301), false);
    assert.equal(verifySignature("", "1000", body, sign("1000"), 1000), false);
    assert.equal(verifySignature(secret, "abc", body, sign("abc"), 1000), false);
});
