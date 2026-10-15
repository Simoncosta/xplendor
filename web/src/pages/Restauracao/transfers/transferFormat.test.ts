import { buildCircuit, qtyLabel, sendDisabledReason, transferSteps, unitWord } from "./transferFormat";
import { TransferRoute } from "common/models/transfer.model";

const route = (over: Partial<TransferRoute> = {}): TransferRoute => ({
    id: 1, name: "Economato → Baixa", origin_warehouse_id: "BO", origin_name: "Armazém Yuko BO", dest_warehouse_id: "BAIXA", dest_name: "Armazém Yuko Baixa",
    origin_store_id: "S1", dest_store_id: "S2", exit_docconfig_id: "1102", exit_doc_label: "Guia de ativos próprios", auto_receive: true, active: true,
    load_address: null, unload_address: null, addresses_ok: true, ...over,
});

describe("transferFormat", () => {
    it("desenha o circuito: uma origem, os destinos em coluna, a receção automática ou manual na ligação", () => {
        const { nodes, edges } = buildCircuit([route(), route({ id: 2, dest_warehouse_id: "YUKO", dest_name: "Armazém Yuko", auto_receive: false, addresses_ok: false })]);
        expect(nodes.map((n) => [n.id, n.kind])).toEqual([["o:BO", "origem"], ["d:BAIXA", "destino"], ["d:YUKO", "destino"]]);
        expect(edges.map((e) => [e.target, e.auto, e.warning, e.label])).toEqual([
            ["d:BAIXA", true, false, "Guia e Entrada automática"],
            ["d:YUKO", false, true, "Guia; o restaurante confirma"],
        ]);
        expect(nodes[0].y).toBeGreaterThan(0); // a origem fica ao meio dos dois destinos
    });

    it("os passos de um envio automático e de um manual", () => {
        const base = { request: { id: 1, document: "ENCF BOENCF/35" }, origin_name: "Armazém Yuko BO", dest_name: "Armazém Yuko", exit_doc_number: "GA BOGA/6", entry_doc_number: null };
        expect(transferSteps({ ...base, status: "a_enviar", auto_receive: true, exit_doc_number: null }).map((s) => s.state)).toEqual(["done", "current", "pending"]);
        const manual = transferSteps({ ...base, status: "enviado", auto_receive: false });
        expect(manual.map((s) => s.state)).toEqual(["done", "done", "current"]);
        expect(manual[2].subtitle).toBe("O Armazém Yuko confirma");
        const done = transferSteps({ ...base, status: "recebido", auto_receive: true, entry_doc_number: "EA BOEA/3" });
        expect(done.map((s) => s.state)).toEqual(["done", "done", "done"]);
        expect(done[2].subtitle).toBe("EA BOEA/3 · entra no Armazém Yuko");
        expect(transferSteps({ ...base, status: "falhou_rececao", auto_receive: true }).map((s) => s.state)).toEqual(["done", "done", "failed"]);
        expect(transferSteps({ ...base, request: null, status: "falhou_envio", auto_receive: true, exit_doc_number: null }).map((s) => s.state)).toEqual(["skipped", "failed", "skipped"]);
    });

    it("explica porque não se pode enviar, pela ordem certa", () => {
        const prep = { blocked: false, needs_confirmation: false, send_block: null, lines: [{}] };
        expect(sendDisabledReason(prep, false)).toBe("Só o gestor do Economato pode enviar.");
        expect(sendDisabledReason({ ...prep, lines: [] }, true)).toBe("Acrescente pelo menos um artigo.");
        expect(sendDisabledReason({ ...prep, blocked: true, needs_confirmation: true }, true)).toBe("Há linhas bloqueadas: resolva-as antes de enviar.");
        expect(sendDisabledReason({ ...prep, needs_confirmation: true }, true)).toBe("Confirme os preços assinalados antes de enviar.");
        expect(sendDisabledReason({ ...prep, send_block: "Falta a morada de carga." }, true)).toBe("Falta a morada de carga.");
        expect(sendDisabledReason(prep, true)).toBeNull();
        expect(qtyLabel(2, "Caixa 6", 6, "Unidade")).toBe("2 Caixa 6 (× 6 = 12 unidades)");
        expect(unitWord("Litro", 2)).toBe("litros");
        expect(unitWord("Unidade", 1)).toBe("unidade");
    });
});
