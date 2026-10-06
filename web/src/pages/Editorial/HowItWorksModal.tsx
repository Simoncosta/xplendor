import { useEffect, useMemo, useState } from "react";
import { Button, Modal, ModalBody, ModalHeader } from "reactstrap";
import { useNavigate } from "react-router-dom";
import { Background, Handle, MarkerType, Position, ReactFlow, type Edge, type Node, type NodeProps } from "@xyflow/react";
import "@xyflow/react/dist/style.css";
import { STAGE_META, Stage } from "common/models/editorialWorkflow.model";

/**
 * "Como funciona" da Linha Editorial: o caminho do Perfil da Marca até à Análise, com os
 * ramos do criativo sugerido (redes) e do artigo (Site). Layout limpo: nós do mesmo
 * tamanho num caminho reto (sem serpentina), ramos por cima e por baixo, ligações
 * animadas no caminho, a etapa atual destacada e os passos bloqueados discretos.
 * Este módulo (e o React Flow) só é carregado quando o modal abre. No telemóvel, na vertical.
 */

type Status = "normal" | "done" | "current" | "locked";
type StepData = { title: string; subtitle: string; icon: string; hex: string; status: Status; count?: number; vertical: boolean };
type StepNode = Node<StepData, "step">;

const W = 132;
const H = 70;

function StepNodeView({ data }: NodeProps<StepNode>) {
    const current = data.status === "current";
    const done = data.status === "done";
    const locked = data.status === "locked";
    const accent = current ? "var(--vz-warning)" : done ? "var(--vz-success)" : data.hex;
    const [inPos, outPos] = data.vertical ? [Position.Top, Position.Bottom] : [Position.Left, Position.Right];
    return (
        <div className={current ? "xp-flow-current" : undefined} title={`${data.title}: ${data.subtitle}`}
            style={{
                width: W, height: H, borderRadius: 10, padding: "8px 10px", fontSize: 12, lineHeight: 1.25, overflow: "hidden",
                background: current ? "var(--vz-warning-bg-subtle)" : done ? "var(--vz-success-bg-subtle)" : "var(--vz-secondary-bg)",
                color: "var(--vz-body-color)", border: `${current ? 2 : 1}px solid ${current || done ? accent : "var(--vz-border-color)"}`,
                borderTop: `4px solid ${accent}`, opacity: locked ? 0.45 : 1, cursor: current ? "pointer" : "default",
            }}>
            <Handle type="target" position={inPos} style={{ opacity: 0 }} isConnectable={false} />
            <Handle type="source" position={outPos} style={{ opacity: 0 }} isConnectable={false} />
            {/* Ramos: entram e saem pelos lados livres. */}
            <Handle id="branch-in" type="target" position={data.vertical ? Position.Left : Position.Top} style={{ opacity: 0 }} isConnectable={false} />
            <Handle id="branch-out" type="source" position={data.vertical ? Position.Right : Position.Bottom} style={{ opacity: 0 }} isConnectable={false} />
            <Handle id="branch-out-top" type="source" position={data.vertical ? Position.Right : Position.Top} style={{ opacity: 0 }} isConnectable={false} />
            <Handle id="branch-in-bottom" type="target" position={data.vertical ? Position.Right : Position.Bottom} style={{ opacity: 0 }} isConnectable={false} />
            <div className="d-flex align-items-center gap-1 fw-semibold text-truncate">
                <i className={locked ? "ri-lock-line" : done ? "ri-checkbox-circle-fill text-success" : data.icon} style={locked || done ? undefined : { color: accent }} />
                <span className="text-truncate">{data.title}</span>
            </div>
            <div className={current ? "text-warning-emphasis fw-medium" : "text-muted"} style={{ display: "-webkit-box", WebkitLineClamp: 2, WebkitBoxOrient: "vertical", overflow: "hidden" }}>
                {data.count ? `${data.count} ${data.count === 1 ? "publicação" : "publicações"}` : data.subtitle}
            </div>
        </div>
    );
}

const nodeTypes = { step: StepNodeView };
type Key = "profile" | "ideas" | "planning" | "creative" | "article" | "production" | "internal_review" | "client_review" | "scheduled" | "published" | "analysis";
const MAIN: Key[] = ["profile", "ideas", "planning", "production", "internal_review", "client_review", "scheduled", "published", "analysis"];
const STAGE_KEYS: Stage[] = ["planning", "production", "internal_review", "client_review", "scheduled", "published", "analysis"];

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

type Props = {
    isOpen: boolean;
    toggle: () => void;
    /** null = ainda a carregar */
    profileReady: boolean | null;
    blockedReason: string | null;
    canProduce: boolean;
    /** Publicações do mês por etapa (para destacar a etapa atual). */
    stageCounts?: Partial<Record<Stage, number>>;
};

export default function HowItWorksModal({ isOpen, toggle, profileReady, blockedReason, canProduce, stageCounts = {} }: Props) {
    const navigate = useNavigate();
    const mobile = useIsMobile();
    const dark = document.documentElement.getAttribute("data-bs-theme") === "dark";
    const blocked = profileReady === false;
    const total = Object.values(stageCounts).reduce((a, b) => a + (b ?? 0), 0);

    // Etapa atual: o perfil (se incompleto); senão as ideias (mês sem publicações); senão a
    // primeira etapa com publicações à espera.
    const current: Key = blocked ? "profile" : total === 0 ? "ideas"
        : (STAGE_KEYS.find((s) => (stageCounts[s] ?? 0) > 0 && s !== "published" && s !== "analysis") ?? "analysis") as Key;

    const { nodes, edges, height } = useMemo(() => {
        const gap = mobile ? 26 : 22;
        const step = (mobile ? H : W) + gap;
        const pos = (k: Key): { x: number; y: number } => {
            const i = MAIN.indexOf(k);
            if (i >= 0) return mobile ? { x: 0, y: i * step } : { x: i * step, y: 0 };
            // Ramos: o criativo entre Planeamento e Produção (por cima / à direita); o artigo
            // do Site por baixo / à direita, entre Planeamento e Publicado.
            if (k === "creative") return mobile ? { x: W + 40, y: 2.5 * step } : { x: 2.5 * step, y: -(H + 46) };
            return mobile ? { x: W + 40, y: 5 * step } : { x: 5 * step, y: H + 46 };
        };
        const statusOf = (k: Key): Status => {
            if (k === "profile") return profileReady ? "done" : blocked ? "current" : "normal";
            if (blocked) return "locked";
            return k === current ? "current" : "normal";
        };
        const data: Record<Key, Omit<StepData, "status" | "vertical">> = {
            profile: { title: "Perfil da Marca", icon: "ri-user-star-line", hex: "#405189", subtitle: profileReady ? "Preenchido" : blocked ? "À espera do preenchimento" : "Tom, público e pilares" },
            ideas: { title: "Gerar ideias", icon: "ri-lightbulb-flash-line", hex: "#405189", subtitle: canProduce ? "A IA propõe; aceita as que quiser" : "Feito pela equipa XPLENDOR" },
            creative: { title: "Criativo", icon: "ri-magic-line", hex: "#f06548", subtitle: "Sugestão para as redes" },
            article: { title: "Escrever artigo", icon: "ri-article-line", hex: "#0ab39c", subtitle: "Site, pelo fluxo do Blog" },
        } as Record<Key, Omit<StepData, "status" | "vertical">>;
        for (const s of STAGE_KEYS) data[s as Key] = { title: STAGE_META[s].label, subtitle: STAGE_META[s].hint, icon: STAGE_META[s].icon, hex: STAGE_META[s].hex, count: stageCounts[s] };

        const keys: Key[] = [...MAIN, "creative", "article"];
        const nodes: StepNode[] = keys.map((k) => ({
            id: k, type: "step", position: pos(k), width: W, height: H, draggable: false, selectable: false, connectable: false,
            data: { ...data[k], status: statusOf(k), vertical: mobile }, ariaLabel: `${data[k].title}: ${data[k].subtitle}`,
        }));

        const color = "var(--vz-secondary-color)";
        const marker = { type: MarkerType.ArrowClosed, color, width: 14, height: 14 };
        const main: Edge[] = MAIN.slice(1).map((k, i) => ({
            id: `m-${i}`, source: MAIN[i], target: k, type: "straight", animated: !blocked, markerEnd: marker,
            style: { stroke: color, strokeWidth: 1.5, opacity: blocked && i > 0 ? 0.35 : 1 },
        }));
        const branch = (id: string, source: Key, target: Key, sourceHandle: string, targetHandle: string): Edge => ({
            id, source, target, sourceHandle, targetHandle, type: "smoothstep", markerEnd: marker,
            style: { stroke: color, strokeDasharray: "5 4", strokeWidth: 1.2, opacity: blocked ? 0.35 : 0.9 },
        });
        const edges = [
            ...main,
            branch("c1", "planning", "creative", "branch-out-top", "branch-in"), branch("c2", "creative", "production", "branch-out", "branch-in"),
            branch("a1", "planning", "article", "branch-out", "branch-in"), branch("a2", "article", "published", "branch-out", "branch-in-bottom"),
        ];
        return { nodes, edges, height: mobile ? MAIN.length * step + 30 : 2 * (H + 46) + H + 40 };
    }, [mobile, blocked, profileReady, canProduce, current, stageCounts]);

    const goProfile = () => { toggle(); navigate("/brand-profile"); };

    return (
        <Modal isOpen={isOpen} toggle={toggle} size="xl" centered scrollable>
            <ModalHeader toggle={toggle}>Como funciona a Linha Editorial</ModalHeader>
            <ModalBody>
                <style>{`@keyframes xp-flow-pulse{0%,100%{box-shadow:0 0 0 0 rgba(247,184,75,.55)}50%{box-shadow:0 0 0 6px rgba(247,184,75,0)}}.xp-flow-current{animation:xp-flow-pulse 1.8s ease-in-out infinite}@media (prefers-reduced-motion: reduce){.xp-flow-current{animation:none}.xp-flow .react-flow__edge-path{animation:none!important}}`}</style>
                <p className="text-muted fs-13">
                    Cada publicação percorre estas etapas, da esquerda para a direita. O Perfil da Marca dá o contexto às ideias e aos textos. Nas redes, há um criativo sugerido;
                    no Site, o artigo segue o fluxo do Blog. A etapa destacada é onde está o trabalho agora.
                </p>
                {blocked && (
                    <div className="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2 fs-13 py-2">
                        <span><i className="ri-error-warning-line me-1" />{blockedReason ?? "Preencha o Perfil da Marca para começar."}</span>
                        <Button color="warning" size="sm" onClick={goProfile}><i className="ri-user-star-line me-1" />Preencher o Perfil da Marca</Button>
                    </div>
                )}
                <div className="xp-flow" style={{ height, width: "100%" }} aria-label="Diagrama do fluxo da Linha Editorial">
                    <ReactFlow nodes={nodes} edges={edges} nodeTypes={nodeTypes} colorMode={dark ? "dark" : "light"}
                        fitView fitViewOptions={{ padding: 0.04, maxZoom: 1 }} minZoom={0.3} maxZoom={1} proOptions={{ hideAttribution: true }}
                        nodesDraggable={false} nodesConnectable={false} elementsSelectable={false}
                        panOnDrag={false} panOnScroll={false} zoomOnScroll={false} zoomOnPinch={false} zoomOnDoubleClick={false} preventScrolling={false}
                        onNodeClick={(_, n) => { if (n.id === "profile" && blocked) goProfile(); }}
                        style={{ background: "transparent" }}>
                        <Background gap={20} size={1} color="var(--vz-border-color)" />
                    </ReactFlow>
                </div>
                {profileReady && <p className="text-success fs-13 mb-0 mt-2"><i className="ri-checkbox-circle-line me-1" />O Perfil da Marca tem o necessário para gerar ideias.</p>}
            </ModalBody>
        </Modal>
    );
}
