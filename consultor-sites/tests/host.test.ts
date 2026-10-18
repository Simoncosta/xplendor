import { test } from "node:test";
import assert from "node:assert/strict";
import { normalizeHost, resolveHost } from "../lib/host.ts";

test("o host perde a porta e fica em minúsculas", () => {
    assert.equal(resolveHost("SimonCosta.Xplendor.Tech:443", ""), "simoncosta.xplendor.tech");
    assert.equal(resolveHost("simoncosta.xplendor.tech.", ""), "simoncosta.xplendor.tech");
    assert.equal(normalizeHost(null), "");
});

test("o mapa de dev traduz o host local no host do site", () => {
    const map = "localhost:3001=simoncosta.localhost,127.0.0.1:3001=simoncosta.localhost";
    assert.equal(resolveHost("localhost:3001", map), "simoncosta.localhost");
    assert.equal(resolveHost("127.0.0.1:3001", map), "simoncosta.localhost");
    assert.equal(resolveHost("outro.localhost:3001", map), "outro.localhost");
});

test("sem mapa (produção), o host local não vira o de outro site", () => {
    assert.equal(resolveHost("localhost:3001", ""), "localhost");
});
