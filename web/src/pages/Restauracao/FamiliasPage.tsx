import { useCallback, useEffect, useMemo, useState } from "react";
import { Card, Container, Row, Col, Spinner, Collapse } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import { useIsMobile } from "../../hooks/useIsMobile";
import { getPingwinFamilies, syncPingwinFamilies } from "helpers/laravel_helper";
import { PingwinFamilyNode } from "common/models/pingwin.model";

/**
 * XPLENDOR — Restauração › Famílias (Fase 1, só leitura). Árvore de famílias de
 * artigos do PingWin (profundidade infinita). Tree view RECURSIVA com o Collapse
 * do reactstrap (Opção A do spike — sem instalar libs). A árvore vem já montada
 * do backend (flat→nested por parent). Tema --vz-* (claro/escuro), responsivo:
 * a indentação vive num contentor com scroll horizontal contido (sem empurrar a
 * página). Só empresas com o módulo pingwin.
 */

const fmtDateTime = (d?: string | null) =>
    d ? new Date(d).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—";

/** Todos os pingwin_id da árvore (para expandir tudo). */
const collectIds = (nodes: PingwinFamilyNode[], acc: string[] = []): string[] => {
    for (const n of nodes) {
        if (n.children?.length) { acc.push(n.pingwin_id); collectIds(n.children, acc); }
    }
    return acc;
};

interface TreeNodeProps {
    node: PingwinFamilyNode;
    level: number;
    indent: number;
    isOpen: (id: string) => boolean;
    onToggle: (id: string) => void;
}

/** Nó recursivo: setinha (roda quando aberto) + Collapse com os filhos. */
function TreeNode({ node, level, indent, isOpen, onToggle }: TreeNodeProps) {
    const hasChildren = (node.children?.length ?? 0) > 0;
    const open = hasChildren && isOpen(node.pingwin_id);

    return (
        <div>
            <div
                className="d-flex align-items-center py-1"
                role={hasChildren ? "button" : undefined}
                onClick={hasChildren ? () => onToggle(node.pingwin_id) : undefined}
                style={{ paddingLeft: level * indent, cursor: hasChildren ? "pointer" : "default", minHeight: 34 }}
            >
                {hasChildren ? (
                    <i
                        className="ri-arrow-right-s-line fs-18 text-muted flex-shrink-0"
                        style={{ transition: "transform .15s", transform: open ? "rotate(90deg)" : "none", width: 22, textAlign: "center" }}
                    />
                ) : (
                    <span className="flex-shrink-0" style={{ display: "inline-block", width: 22 }} />
                )}
                <i className={`${hasChildren ? "ri-folder-3-line text-warning" : "ri-price-tag-3-line text-muted"} me-2 flex-shrink-0`} />
                <span className="text-body" style={{ wordBreak: "break-word" }}>{node.description || "—"}</span>
                {node.item_count > 0 && (
                    <span className="badge bg-primary-subtle text-primary ms-2 flex-shrink-0" title="Artigos nesta família">
                        {node.item_count}
                    </span>
                )}
            </div>

            {hasChildren && (
                <Collapse isOpen={open}>
                    {node.children.map((child) => (
                        <TreeNode key={child.pingwin_id} node={child} level={level + 1} indent={indent} isOpen={isOpen} onToggle={onToggle} />
                    ))}
                </Collapse>
            )}
        </div>
    );
}

export default function FamiliasPage() {
    document.title = "Famílias | Restauração | Xplendor";
    const isMobile = useIsMobile();
    const indent = isMobile ? 16 : 22; // indentação menor em mobile

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        try { return Number(JSON.parse(authUser).company_id || 0); } catch { return 0; }
    }, []);

    const [tree, setTree] = useState<PingwinFamilyNode[]>([]);
    const [total, setTotal] = useState(0);
    const [lastSynced, setLastSynced] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [expanded, setExpanded] = useState<Set<string>>(new Set());

    const fetchTree = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getPingwinFamilies(companyId);
            setTree(res?.data?.tree ?? []);
            setTotal(res?.data?.total ?? 0);
            setLastSynced(res?.data?.last_synced_at ?? null);
        } catch {
            setTree([]);
        } finally {
            setLoading(false);
        }
    }, [companyId]);

    useEffect(() => { fetchTree(); }, [fetchTree]);

    const isOpen = useCallback((id: string) => expanded.has(id), [expanded]);
    const toggle = useCallback((id: string) => {
        setExpanded((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    }, []);

    const expandAll = () => setExpanded(new Set(collectIds(tree)));
    const collapseAll = () => setExpanded(new Set());

    const runSync = async () => {
        if (!companyId) return;
        setSyncing(true);
        try {
            await syncPingwinFamilies(companyId);
            toast.info("A sincronizar famílias… vais ser notificado no sino quando terminar.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível sincronizar as famílias.");
        } finally {
            setSyncing(false);
        }
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row>
                    <Col xs={12}>
                        <div className="page-title-box d-sm-flex align-items-center justify-content-between">
                            <div>
                                <h4 className="mb-sm-0">Famílias</h4>
                                <small className="text-muted">Árvore de famílias de artigos do PingWin (só leitura). Última sincronização: {fmtDateTime(lastSynced)}</small>
                            </div>
                            <button className="btn btn-primary" onClick={runSync} disabled={syncing}>
                                {syncing ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-refresh-line me-1" /> Sincronizar</>}
                            </button>
                        </div>
                    </Col>
                </Row>

                <Row>
                    <Col xs={12}>
                        <Card className="mb-3">
                            <div className="card-header">
                                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                    <h5 className="card-title mb-0">
                                        Árvore de famílias {loading && <Spinner size="sm" className="ms-1" />}
                                        {total > 0 && <span className="badge bg-light text-muted ms-2">{total}</span>}
                                    </h5>
                                    {tree.length > 0 && (
                                        <div className="d-flex gap-2">
                                            <button className="btn btn-sm btn-soft-secondary" onClick={expandAll}>
                                                <i className="ri-node-tree me-1" /> Expandir tudo
                                            </button>
                                            <button className="btn btn-sm btn-soft-secondary" onClick={collapseAll}>
                                                <i className="ri-contract-up-down-line me-1" /> Colapsar tudo
                                            </button>
                                        </div>
                                    )}
                                </div>
                            </div>

                            <div className="card-body">
                                {/* Contentor com scroll horizontal contido: a indentação de níveis
                                    profundos NUNCA empurra a página para fora do ecrã (mobile). */}
                                <div style={{ overflowX: "auto" }}>
                                    {!loading && tree.length === 0 ? (
                                        <div className="text-center text-muted py-4">
                                            Sem famílias. Usa <strong>“Sincronizar”</strong> para as obter do PingWin.
                                        </div>
                                    ) : (
                                        <div style={{ minWidth: "fit-content" }}>
                                            {tree.map((node) => (
                                                <TreeNode key={node.pingwin_id} node={node} level={0} indent={indent} isOpen={isOpen} onToggle={toggle} />
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </div>
                        </Card>
                    </Col>
                </Row>
            </Container>
        </div>
    );
}
