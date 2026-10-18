import { test } from "node:test";
import assert from "node:assert/strict";
import { mapConfig } from "../lib/map.ts";

test("sem variável (dev, testes), não há mapa com mosaicos: imagem estática", () => {
    assert.equal(mapConfig({}), null);
    assert.equal(mapConfig({ MAP_TILE_URL: "" }), null);
});

test("só aceita um endereço https com {z}, {x} e {y}", () => {
    assert.equal(mapConfig({ MAP_TILE_URL: "http://tiles.exemplo.invalid/{z}/{x}/{y}.png" }), null);
    assert.equal(mapConfig({ MAP_TILE_URL: "https://tiles.exemplo.invalid/mapa.png" }), null);
    assert.deepEqual(mapConfig({ MAP_TILE_URL: "https://tiles.exemplo.invalid/{z}/{x}/{y}.png", MAP_ATTRIBUTION: "Exemplo" }),
        { tileUrl: "https://tiles.exemplo.invalid/{z}/{x}/{y}.png", attribution: "Exemplo" });
});
