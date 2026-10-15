import { TransferRoute, TransferStatus, TransferSummary } from "common/models/transfer.model";

/** XPLENDOR — Fatia 7c: os estados, o circuito e os passos de um envio (funções puras, testadas). */
export const TRANSFER_STATUS: Record<TransferStatus, { label: string; color: string; icon: string }> = {
    a_enviar: { label: "A enviar", color: "info", icon: "ri-loader-4-line" },
    enviado: { label: "Enviado, à espera da receção", color: "primary", icon: "ri-truck-line" },
    recebido: { label: "Recebido", color: "success", icon: "ri-checkbox-circle-line" },
    recebido_com_diferenca: { label: "Recebido com diferença", color: "warning", icon: "ri-error-warning-line" },
    falhou_envio: { label: "O envio falhou", color: "danger", icon: "ri-close-circle-line" },
    falhou_rececao: { label: "A entrada falhou", color: "danger", icon: "ri-close-circle-line" },
    anulado: { label: "Anulado", color: "secondary", icon: "ri-forbid-line" },
};

export type StepState = "done" | "current" | "failed" | "pending" | "skipped";
export type FlowStep = { key: "pedido" | "envio" | "rececao"; title: string; subtitle: string; state: StepState };

/** Os três passos de um envio: Pedido → Envio (Guia) → Receção (Entrada). */
export function transferSteps(t: Pick<TransferSummary, "status" | "request" | "exit_doc_number" | "entry_doc_number" | "auto_receive" | "origin_name" | "dest_name">): FlowStep[] {
    const s = t.status;
    const pedido: FlowStep = t.request
        ? { key: "pedido", title: "Pedido", subtitle: t.request.document ?? "Encomenda do restaurante", state: "done" }
        : { key: "pedido", title: "Pedido", subtitle: "Envio sem encomenda", state: "skipped" };
    const envioState: StepState = s === "a_enviar" ? "current" : s === "falhou_envio" ? "failed" : s === "anulado" && !t.exit_doc_number ? "skipped" : "done";
    const envio: FlowStep = { key: "envio", title: "Envio", subtitle: t.exit_doc_number ? `${t.exit_doc_number} · sai do ${t.origin_name}` : `Guia no ${t.origin_name}`, state: envioState };
    const recState: StepState = s === "recebido" || s === "recebido_com_diferenca" ? "done" : s === "falhou_rececao" ? "failed"
        : s === "enviado" ? "current" : s === "anulado" || s === "falhou_envio" ? "skipped" : "pending";
    const rececao: FlowStep = {
        key: "rececao", title: "Receção",
        subtitle: t.entry_doc_number ? `${t.entry_doc_number} · entra no ${t.dest_name}` : t.auto_receive ? `Automática no ${t.dest_name}` : `O ${t.dest_name} confirma`,
        state: recState,
    };
    return [pedido, envio, rececao];
}

export type CircuitNode = { id: string; label: string; kind: "origem" | "destino"; x: number; y: number; inactive: boolean };
export type CircuitEdge = { id: string; source: string; target: string; label: string; auto: boolean; active: boolean; warning: boolean };

/** O mapa do circuito: cada armazém de origem à esquerda e os destinos em coluna à direita. */
export function buildCircuit(routes: TransferRoute[], gapY = 110, colX = 560): { nodes: CircuitNode[]; edges: CircuitEdge[] } {
    const origins = Array.from(new Map(routes.map((r) => [r.origin_warehouse_id, r.origin_name])).entries());
    const dests = Array.from(new Map(routes.map((r) => [r.dest_warehouse_id, r.dest_name])).entries());
    const span = Math.max(dests.length, origins.length);
    const nodes: CircuitNode[] = [
        ...origins.map(([id, name], i) => ({ id: `o:${id}`, label: name, kind: "origem" as const, x: 0, y: ((span - origins.length) / 2 + i) * gapY, inactive: false })),
        ...dests.map(([id, name], i) => ({
            id: `d:${id}`, label: name, kind: "destino" as const, x: colX, y: ((span - dests.length) / 2 + i) * gapY,
            inactive: routes.filter((r) => r.dest_warehouse_id === id).every((r) => !r.active),
        })),
    ];
    const edges: CircuitEdge[] = routes.map((r) => ({
        id: `r:${r.id}`, source: `o:${r.origin_warehouse_id}`, target: `d:${r.dest_warehouse_id}`,
        label: r.auto_receive ? "Guia e Entrada automática" : "Guia; o restaurante confirma",
        auto: r.auto_receive, active: r.active, warning: !r.addresses_ok,
    }));
    return { nodes, edges };
}

/** A unidade de stock em minúsculas e no plural certo ("1 unidade", "12 unidades", "2 litros"). */
export function unitWord(unit: string, n: number): string {
    const u = unit.toLowerCase();
    if (Math.abs(n) === 1) return u;
    return /[aeiou]$/.test(u) ? `${u}s` : /[rz]$/.test(u) ? `${u}es` : u;
}

/** "2 Grade 24 (× 24 = 48 unidades)". */
export function qtyLabel(qty: number, unit: string, factor: number | null, stockUnit: string): string {
    const n = (v: number) => v.toLocaleString("pt-PT", { maximumFractionDigits: 3 });
    if (factor === null) return `${n(qty)} ${unit}`;
    if (unit.toLowerCase() === stockUnit.toLowerCase()) return `${n(qty)} ${unit}`;
    return `${n(qty)} ${unit} (× ${n(factor)} = ${n(qty * factor)} ${unitWord(stockUnit, qty * factor)})`;
}

/** Porque não se pode enviar (null = pode). A ordem é a que a pessoa resolve primeiro. */
export function sendDisabledReason(prep: { blocked: boolean; needs_confirmation: boolean; send_block: string | null; lines: unknown[] } | null, canSend: boolean): string | null {
    if (!canSend) return "Só o gestor do Economato pode enviar.";
    if (!prep) return "A preparar o envio.";
    if (!prep.lines.length) return "Acrescente pelo menos um artigo.";
    if (prep.blocked) return "Há linhas bloqueadas: resolva-as antes de enviar.";
    if (prep.needs_confirmation) return "Confirme os preços assinalados antes de enviar.";
    return prep.send_block;
}
