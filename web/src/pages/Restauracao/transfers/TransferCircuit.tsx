import { useEffect, useMemo, useState } from "react";
import { Background, Handle, MarkerType, Position, ReactFlow, type Edge, type Node, type NodeProps } from "@xyflow/react";
import "@xyflow/react/dist/style.css";
import { TransferRoute, TransferSummary } from "common/models/transfer.model";
import { FlowStep, StepState, buildCircuit, transferSteps } from "./transferFormat";

/**
 * XPLENDOR — Fatia 7c: o circuito das transferências em React Flow (só leitura: sem arrastar, sem ligar,
 * sem zoom com a roda). Dois desenhos: o mapa (Economato → restaurantes, com o documento de saída e a
 * receção automática ou manual) e o caminho de um envio (Pedido → Envio → Receção). No telemóvel
 * (< 640 px) é uma lista vertical.
 */
const MOBILE = "(max-width: 639.98px)";

export function useIsNarrow() {
    const [narrow, setNarrow] = useState(() => typeof window !== "undefined" && !!window.matchMedia && window.matchMedia(MOBILE).matches);
    useEffect(() => {
        if (!window.matchMedia) return;
        const mq = window.matchMedia(MOBILE);
        const on = () => setNarrow(mq.matches);
        mq.addEventListener?.("change", on);
        return () => mq.removeEventListener?.("change", on);
    }, []);
    return narrow;
}

const W = 200;
const H = 64;

type BoxData = { title: string; subtitle: string; icon: string; tone: "primary" | "success" | "warning" | "danger" | "secondary" | "info"; muted?: boolean };
type BoxNode = Node<BoxData, "box">;

function BoxView({ data }: NodeProps<BoxNode>) {
    return (
        <div title={`${data.title}: ${data.subtitle}`} style={{
            width: W, minHeight: H, borderRadius: 10, padding: "8px 10px", fontSize: 12, lineHeight: 1.3, background: "var(--vz-secondary-bg)",
            color: "var(--vz-body-color)", border: "1px solid var(--vz-border-color)", borderTop: `4px solid var(--vz-${data.tone})`, opacity: data.muted ? 0.5 : 1,
        }}>
            <Handle type="target" position={Position.Left} style={{ opacity: 0 }} isConnectable={false} />
            <Handle type="source" position={Position.Right} style={{ opacity: 0 }} isConnectable={false} />
            <div className="d-flex align-items-center gap-1 fw-semibold text-truncate"><i className={data.icon} style={{ color: `var(--vz-${data.tone})` }} />{data.title}</div>
            <div className="text-muted text-truncate">{data.subtitle}</div>
        </div>
    );
}
const nodeTypes = { box: BoxView };

const STEP_TONE: Record<StepState, BoxData["tone"]> = { done: "success", current: "warning", failed: "danger", pending: "secondary", skipped: "secondary" };
const STEP_ICON: Record<FlowStep["key"], string> = { pedido: "ri-file-list-3-line", envio: "ri-truck-line", rececao: "ri-inbox-archive-line" };
const STEP_WORD: Record<StepState, string> = { done: "Feito", current: "Em curso", failed: "Falhou", pending: "Por fazer", skipped: "Não se aplica" };

function Canvas({ nodes, edges, height }: { nodes: Node[]; edges: Edge[]; height: number }) {
    return (
        <div style={{ height, width: "100%" }} data-testid="transfer-canvas">
            <ReactFlow nodes={nodes} edges={edges} nodeTypes={nodeTypes} fitView fitViewOptions={{ padding: 0.15 }} proOptions={{ hideAttribution: true }}
                nodesDraggable={false} nodesConnectable={false} elementsSelectable={false} edgesFocusable={false} nodesFocusable={false}
                panOnDrag={false} zoomOnScroll={false} zoomOnPinch={false} zoomOnDoubleClick={false} preventScrolling={false}>
                <Background gap={20} size={1} />
            </ReactFlow>
        </div>
    );
}

/** O mapa do circuito: Economato → restaurantes. */
export function CircuitMap({ routes }: { routes: TransferRoute[] }) {
    const narrow = useIsNarrow();
    const { nodes, edges } = useMemo(() => {
        const c = buildCircuit(routes);
        return {
            nodes: c.nodes.map((n): BoxNode => ({
                id: n.id, type: "box", position: { x: n.x, y: n.y }, draggable: false,
                data: { title: n.label, subtitle: n.kind === "origem" ? "Economato (origem)" : "Restaurante (destino)", icon: n.kind === "origem" ? "ri-store-3-line" : "ri-restaurant-line",
                    tone: n.kind === "origem" ? "primary" : "success", muted: n.inactive },
            })),
            edges: c.edges.map((e): Edge => ({
                id: e.id, source: e.source, target: e.target, label: e.warning ? `${e.label} · falta a morada` : e.label, animated: e.active && e.auto,
                style: { stroke: e.warning ? "var(--vz-warning)" : "var(--vz-primary)", strokeDasharray: e.auto ? undefined : "6 4", opacity: e.active ? 1 : 0.4 },
                labelStyle: { fontSize: 11, fill: "var(--vz-body-color)" }, labelBgStyle: { fill: "var(--vz-card-bg, var(--vz-body-bg))" }, labelBgPadding: [6, 3], labelBgBorderRadius: 4,
                markerEnd: { type: MarkerType.ArrowClosed },
            })),
        };
    }, [routes]);

    if (narrow) {
        return (
            <ul className="list-unstyled mb-0" data-testid="circuit-list">
                {routes.map((r) => (
                    <li key={r.id} className={`border rounded p-2 mb-2 ${r.active ? "" : "opacity-50"}`}>
                        <div className="fw-semibold"><i className="ri-store-3-line text-primary me-1" />{r.origin_name}</div>
                        <div className="text-muted small ms-3"><i className="ri-arrow-down-line me-1" />{r.exit_doc_label}</div>
                        <div className="fw-semibold"><i className="ri-restaurant-line text-success me-1" />{r.dest_name}</div>
                        <div className="small mt-1">{r.auto_receive ? "Receção automática" : "O restaurante confirma a receção"}{r.addresses_ok ? "" : " · falta a morada"}</div>
                    </li>
                ))}
            </ul>
        );
    }
    const rows = Math.max(1, new Set(routes.map((r) => r.dest_warehouse_id)).size);
    return <Canvas nodes={nodes} edges={edges} height={Math.max(180, rows * 110 + 40)} />;
}

/** O caminho de um envio: Pedido → Envio → Receção. */
export function TransferPath({ transfer }: { transfer: TransferSummary }) {
    const narrow = useIsNarrow();
    const steps = transferSteps(transfer);
    const { nodes, edges } = useMemo(() => ({
        nodes: steps.map((s, i): BoxNode => ({
            id: s.key, type: "box", position: { x: i * (W + 60), y: 0 }, draggable: false,
            data: { title: `${s.title} · ${STEP_WORD[s.state]}`, subtitle: s.subtitle, icon: STEP_ICON[s.key], tone: STEP_TONE[s.state], muted: s.state === "skipped" },
        })),
        edges: steps.slice(1).map((s, i): Edge => ({
            id: `${steps[i].key}-${s.key}`, source: steps[i].key, target: s.key, animated: s.state === "current",
            style: { stroke: s.state === "failed" ? "var(--vz-danger)" : "var(--vz-primary)" }, markerEnd: { type: MarkerType.ArrowClosed },
        })),
    }), [steps]);

    if (narrow) {
        return (
            <ol className="list-unstyled mb-0" data-testid="transfer-steps">
                {steps.map((s) => (
                    <li key={s.key} className={`border-start border-3 border-${STEP_TONE[s.state]} ps-2 mb-2 ${s.state === "skipped" ? "opacity-50" : ""}`}>
                        <div className="fw-semibold"><i className={`${STEP_ICON[s.key]} me-1`} />{s.title} · {STEP_WORD[s.state]}</div>
                        <div className="text-muted small">{s.subtitle}</div>
                    </li>
                ))}
            </ol>
        );
    }
    return <Canvas nodes={nodes} edges={edges} height={130} />;
}
