import { useCallback, useEffect, useState } from "react";
import { Button, Container, Row, Col, Spinner, Collapse } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import { useIsMobile } from "../../hooks/useIsMobile";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import { getPingwinFamilies, syncPingwinFamilies } from "helpers/laravel_helper";
import { PingwinFamilyNode } from "common/models/pingwin.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

/**
 * XPLENDOR — Restauração › Famílias (Fase 1, só leitura). Árvore de famílias de
 * artigos do PingWin (profundidade infinita). Tree view RECURSIVA com o Collapse
 * do reactstrap (Opção A do spike — sem instalar libs). A árvore vem já montada
 * do backend (flat→nested por parent). Tema --vz-* (claro/escuro), responsivo:
 * a indentação vive num contentor com scroll horizontal contido (sem empurrar a
 * página). Só empresas com o módulo pingwin.
 *
 * UI-2a: PageCard (Expandir, Colapsar e Sincronizar no cabeçalho; contagem e sincronização no
 * estado). A árvore fica como estava.
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

    const companyId = useWorkingCompanyId();

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
            toast.info("A sincronizar famílias… será notificado no sino quando terminar.");
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
                <PageHeader
                    title="Famílias"
                    breadcrumbs={[{ label: "Cadastros" }]}
                    info="A árvore de famílias de artigos do PingWin, só para consulta."
                />

                <Row>
                    <Col xs={12}>
                        <PageCard
                            title="Árvore de famílias"
                            flush={false}
                            loading={loading && tree.length > 0}
                            status={<>{total > 0 ? <>{total} {total === 1 ? "família" : "famílias"} · </> : null}Última sincronização: {fmtDateTime(lastSynced)}</>}
                            actions={<>
                                {tree.length > 0 && <>
                                    <Button size="sm" color="outline-primary" onClick={expandAll}>
                                        <i className="ri-node-tree me-1" /> Expandir tudo
                                    </Button>
                                    <Button size="sm" color="outline-primary" onClick={collapseAll}>
                                        <i className="ri-contract-up-down-line me-1" /> Colapsar tudo
                                    </Button>
                                </>}
                                <Button size="sm" color="outline-primary" onClick={runSync} disabled={syncing}>
                                    {syncing ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-refresh-line me-1" /> Sincronizar</>}
                                </Button>
                            </>}
                        >
                            {/* Contentor com scroll horizontal contido: a indentação de níveis
                                profundos NUNCA empurra a página para fora do ecrã (mobile). */}
                            <div style={{ overflowX: "auto" }}>
                                {loading && tree.length === 0 ? (
                                    <div className="placeholder-glow py-2" data-testid="dt-skeleton">
                                        {Array.from({ length: 6 }).map((_, i) => <div key={i} className="mb-2"><span className={`placeholder col-${i % 2 ? 4 : 6}`} /></div>)}
                                    </div>
                                ) : tree.length === 0 ? (
                                    <div className="xp-dt-empty" data-testid="dt-empty">
                                        <div>Ainda não há famílias. Sincronize para as obter do PingWin.</div>
                                        <div className="mt-2"><Button color="outline-primary" size="sm" onClick={runSync} disabled={syncing}><i className="ri-refresh-line me-1" />Sincronizar</Button></div>
                                    </div>
                                ) : (
                                    <div style={{ minWidth: "fit-content" }}>
                                        {tree.map((node) => (
                                            <TreeNode key={node.pingwin_id} node={node} level={0} indent={indent} isOpen={isOpen} onToggle={toggle} />
                                        ))}
                                    </div>
                                )}
                            </div>
                        </PageCard>
                    </Col>
                </Row>
            </Container>
        </div>
    );
}
