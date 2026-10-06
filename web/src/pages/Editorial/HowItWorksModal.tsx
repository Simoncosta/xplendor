import { useEffect, useMemo, useState } from "react";
import { Button, Modal, ModalBody, ModalHeader } from "reactstrap";
import { useNavigate } from "react-router-dom";
import { Background, Handle, Position, ReactFlow, type Edge, type Node, type NodeProps } from "@xyflow/react";
import "@xyflow/react/dist/style.css";
import { STAGE_META, Stage } from "common/models/editorialWorkflow.model";

/**
 * "Como funciona" da Linha Editorial: o fluxo do Perfil da Marca até à Análise, com o ramo
 * do criativo (redes) e do artigo (Site), e o estado desta empresa. Este módulo (e o React
 * Flow) só é carregado quando o modal abre. No telemóvel, o diagrama fica na vertical.
 */

type Status = "normal" | "done" | "waiting" | "locked";
type StepData = { title: string; subtitle: string; icon: string; hex: string; status: Status };
type StepNode = Node<StepData, "step">;

const SIDES = [["top", Position.Top], ["right", Position.Right], ["bottom", Position.Bottom], ["left", Position.Left]] as const;
const hidden = { opacity: 0, width: 6, height: 6, minWidth: 0, minHeight: 0, border: 0 };

function StepNodeView({ data }: NodeProps<StepNode>) {
    const waiting = data.status === "waiting";
    const done = data.status === "done";
    const locked = data.status === "locked";
    const accent = waiting ? "var(--vz-warning)" : done ? "var(--vz-success)" : data.hex;
    return (
        <div className={`xp-flow-step${waiting ? " xp-flow-waiting" : ""}`}
            style={{
                width: "100%", borderRadius: 8, padding: "8px 10px", fontSize: 12, lineHeight: 1.3,
                background: waiting ? "var(--vz-warning-bg-subtle)" : done ? "var(--vz-success-bg-subtle)" : "var(--vz-secondary-bg)",
                color: "var(--vz-body-color)", border: `${waiting ? 2 : 1}px solid ${waiting || done ? accent : "var(--vz-border-color)"}`,
                borderLeft: `4px solid ${accent}`, opacity: locked ? 0.5 : 1, cursor: waiting ? "pointer" : "default",
            }}>
            {SIDES.map(([id, pos]) => <Handle key={`t-${id}`} id={`t-${id}`} type="target" position={pos} style={hidden} isConnectable={false} />)}
            {SIDES.map(([id, pos]) => <Handle key={`s-${id}`} id={`s-${id}`} type="source" position={pos} style={hidden} isConnectable={false} />)}
            <div className="d-flex align-items-center gap-1 fw-semibold">
                <i className={locked ? "ri-lock-line" : done ? "ri-checkbox-circle-fill text-success" : data.icon} style={locked || done ? undefined : { color: accent }} />
                <span className="text-truncate">{data.title}</span>
            </div>
            <div className={waiting ? "text-warning-emphasis fw-medium" : "text-muted"}>{data.subtitle}</div>
        </div>
    );
}

const nodeTypes = { step: StepNodeView };

// Posições: no computador, em "S" (linha de cima da esquerda para a direita, linha de baixo
// da direita para a esquerda); no telemóvel, numa coluna, com o artigo ao lado.
type Key = "profile" | "ideas" | "planning" | "creative" | "article" | "production" | "internal_review" | "client_review" | "scheduled" | "published" | "analysis";
const DESKTOP: Record<Key, [number, number]> = {
    profile: [0, 0], ideas: [1, 0], planning: [2, 0], creative: [3, 0], production: [4, 0],
    article: [2.5, 1],
    internal_review: [4, 2], client_review: [3, 2], scheduled: [2, 2], published: [1, 2], analysis: [0, 2],
};
const MOBILE: Record<Key, [number, number]> = {
    profile: [0, 0], ideas: [0, 1], planning: [0, 2], creative: [0, 3], article: [1, 4], production: [0, 4],
    internal_review: [0, 5], client_review: [0, 6], scheduled: [0, 7], published: [0, 8], analysis: [0, 9],
};

const useIsMobile = () => {
    const query = "(max-width: 767.98px)";
    const [mobile, setMobile] = useState(() => window.matchMedia(query).matches);
    useEffect(() => {
        const mq = window.matchMedia(query);
        const on = () => setMobile(mq.matches);
        mq.addEventListener("change", on);
        return () => mq.removeEventListener("change", on);
    }, []);
    return mobile;
};

const STAGE_KEYS: Stage[] = ["planning", "production", "internal_review", "client_review", "scheduled", "published", "analysis"];

type Props = {
    isOpen: boolean;
    toggle: () => void;
    /** null = ainda a carregar */
    profileReady: boolean | null;
    blockedReason: string | null;
    canProduce: boolean;
};

export default function HowItWorksModal({ isOpen, toggle, profileReady, blockedReason, canProduce }: Props) {
    const navigate = useNavigate();
    const mobile = useIsMobile();
    const dark = document.documentElement.getAttribute("data-bs-theme") === "dark";
    const blocked = profileReady === false;

    const { nodes, edges, height } = useMemo(() => {
        const w = mobile ? 150 : 170;
        const pos = mobile ? MOBILE : DESKTOP;
        const step = mobile ? { x: 172, y: 86 } : { x: 205, y: 105 };
        const after = (status: Status): Status => (blocked ? "locked" : status);

        const data: Record<Key, StepData> = {
            profile: {
                title: "Perfil da Marca", icon: "ri-user-star-line", hex: "#405189",
                subtitle: profileReady ? "Preenchido" : blocked ? "À espera do preenchimento" : "Tom, público e pilares",
                status: profileReady ? "done" : blocked ? "waiting" : "normal",
            },
            ideas: { title: "Gerar ideias", icon: "ri-lightbulb-flash-line", hex: "#405189", subtitle: canProduce ? "A IA propõe; aceita as que quiser" : "Feito pela equipa XPLENDOR", status: after("normal") },
            creative: { title: "Criativo", icon: "ri-magic-line", hex: "#f06548", subtitle: "Instagram e Facebook", status: after("normal") },
            article: { title: "Escrever artigo", icon: "ri-article-line", hex: "#0ab39c", subtitle: "Site, pelo fluxo do Blog", status: after("normal") },
        } as Record<Key, StepData>;
        for (const s of STAGE_KEYS) {
            const m = STAGE_META[s];
            data[s as Key] = { title: m.label, subtitle: m.hint, icon: m.icon, hex: m.hex, status: after("normal") };
        }

        const nodes: StepNode[] = (Object.keys(pos) as Key[]).map((k) => ({
            id: k, type: "step", data: data[k], position: { x: pos[k][0] * step.x, y: pos[k][1] * step.y },
            width: w, draggable: false, selectable: false, connectable: false, focusable: k === "profile" && blocked,
            ariaLabel: `${data[k].title}: ${data[k].subtitle}`,
        }));

        const color = "var(--vz-secondary-color)";
        const e = (source: Key, target: Key, sh: string, th: string, label?: string, dashed = false): Edge => ({
            id: `${source}-${target}`, source, target, sourceHandle: `s-${sh}`, targetHandle: `t-${th}`, type: "smoothstep", label,
            style: { stroke: color, strokeDasharray: dashed ? "5 4" : undefined }, markerEnd: { type: "arrowclosed" as any, color },
            labelStyle: { fontSize: 11, fill: "var(--vz-body-color)" }, labelBgStyle: { fill: "var(--vz-body-bg)" },
        });
        const edges: Edge[] = mobile ? [
            e("profile", "ideas", "bottom", "top"), e("ideas", "planning", "bottom", "top"),
            e("planning", "creative", "bottom", "top", "Redes"), e("planning", "article", "right", "top", "Site", true),
            e("creative", "production", "bottom", "top"), e("production", "internal_review", "bottom", "top"),
            e("internal_review", "client_review", "bottom", "top"), e("client_review", "scheduled", "bottom", "top"),
            e("scheduled", "published", "bottom", "top"), e("article", "published", "bottom", "right", undefined, true),
            e("published", "analysis", "bottom", "top"),
        ] : [
            e("profile", "ideas", "right", "left"), e("ideas", "planning", "right", "left"),
            e("planning", "creative", "right", "left", "Redes"), e("planning", "article", "bottom", "top", "Site", true),
            e("creative", "production", "right", "left"), e("production", "internal_review", "bottom", "top"),
            e("internal_review", "client_review", "left", "right"), e("client_review", "scheduled", "left", "right"),
            e("scheduled", "published", "left", "right"), e("article", "published", "bottom", "top", undefined, true),
            e("published", "analysis", "left", "right"),
        ];
        const rows = Math.max(...Object.values(pos).map(([, y]) => y)) + 1;
        return { nodes, edges, height: rows * step.y + (mobile ? 40 : 30) };
    }, [mobile, blocked, profileReady, canProduce]);

    const goProfile = () => { toggle(); navigate("/brand-profile"); };

    return (
        <Modal isOpen={isOpen} toggle={toggle} size="xl" centered scrollable>
            <ModalHeader toggle={toggle}>Como funciona a Linha Editorial</ModalHeader>
            <ModalBody>
                <style>{`@keyframes xp-flow-pulse{0%,100%{box-shadow:0 0 0 0 rgba(247,184,75,.55)}50%{box-shadow:0 0 0 6px rgba(247,184,75,0)}}.xp-flow-waiting{animation:xp-flow-pulse 1.8s ease-in-out infinite}@media (prefers-reduced-motion: reduce){.xp-flow-waiting{animation:none}}.xp-flow .react-flow__attribution{background:transparent}.xp-flow .react-flow__attribution a{color:var(--vz-secondary-color)}`}</style>
                <p className="text-muted fs-13">
                    Cada publicação percorre estas etapas. O Perfil da Marca dá o contexto às ideias e aos textos; nas redes, a publicação segue para o criativo e a produção;
                    no Site, para o artigo do Blog.
                </p>
                {blocked && (
                    <div className="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2 fs-13 py-2">
                        <span><i className="ri-error-warning-line me-1" />{blockedReason ?? "Preencha o Perfil da Marca para começar."}</span>
                        <Button color="warning" size="sm" onClick={goProfile}><i className="ri-user-star-line me-1" />Preencher o Perfil da Marca</Button>
                    </div>
                )}
                <div className="xp-flow" style={{ height, width: "100%" }} aria-label="Diagrama do fluxo da Linha Editorial">
                    <ReactFlow nodes={nodes} edges={edges} nodeTypes={nodeTypes} colorMode={dark ? "dark" : "light"}
                        fitView fitViewOptions={{ padding: 0.06, maxZoom: 1 }} minZoom={0.3} maxZoom={1}
                        nodesDraggable={false} nodesConnectable={false} elementsSelectable={false}
                        panOnDrag={false} panOnScroll={false} zoomOnScroll={false} zoomOnPinch={false} zoomOnDoubleClick={false} preventScrolling={false}
                        onNodeClick={(_, n) => { if (n.id === "profile" && blocked) goProfile(); }}
                        style={{ background: "transparent" }}>
                        <Background gap={20} size={1} color="var(--vz-border-color)" />
                    </ReactFlow>
                </div>
                {profileReady && (
                    <p className="text-success fs-13 mb-0 mt-2"><i className="ri-checkbox-circle-line me-1" />O Perfil da Marca tem o necessário para gerar ideias.</p>
                )}
            </ModalBody>
        </Modal>
    );
}
