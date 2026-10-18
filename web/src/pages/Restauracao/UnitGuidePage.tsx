import { useCallback, useEffect, useRef, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { Alert, Badge, Button, Card, CardBody, Col, Container, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import Skeleton from "Components/Common/Skeleton";
import HiddenMoney, { FinancialNote } from "Components/Common/HiddenMoney";
import AuditButton from "pages/Auditoria/AuditButton";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { useCan } from "contexts/ModulesContext";
import { confirmAllUnitGuide, getGlobalUnitGuide, getUnitGuide, getUnitGuideQueue, previewUnitGuide, skipUnitGuide, undoUnitGuideBatch } from "helpers/laravel_helper";
import { GlobalUnitView, UnitGuideView, UnitQueue, UnitQueueItem, UnitVerdictKey } from "common/models/unitGuide.model";
import UnitGuideArticle from "./units/UnitGuideArticle";
import "./units/unitGuide.css";
import GlobalUnitCard from "./units/GlobalUnitCard";

/**
 * XPLENDOR — Auditoria v2: o Guião de unidades (Restauração › Auditoria › Guião de unidades). Uma
 * fila pelo Pareto (€ comprado nos últimos 90 dias), artigo a artigo ("1 de N"), com os "Por
 * identificar" e os enviados ao administrador em filtros. Abre-se também de qualquer achado de
 * unidades (?artigo=). No telemóvel, os passos ficam uns por baixo dos outros.
 */
/** Os grupos da fila, por esta ordem; dentro de cada um, pelo € comprado. */
const GROUPS: { key: UnitVerdictKey; label: string; tone: string }[] = [
    { key: "mudar", label: "Precisa de mudança", tone: "warning" },
    { key: "ajuda", label: "Por identificar", tone: "grey" },
    { key: "certo", label: "Está certo, só confirmar", tone: "success" },
];
const HELP_KEY = "xp-unit-guide-help-seen";
const HOW = "O Guião confirma, artigo a artigo, como cada produto se compra, como se usa nas fichas e como se conta. Os dados vêm das faturas lançadas no PingWin e, quando existem, das fichas técnicas e das contagens.";
const eur0 = (v: number | null) => (v ?? 0).toLocaleString("pt-PT", { maximumFractionDigits: 0 }) + " €";

type Fila = "todos" | "por_identificar" | "administrador";
const FILAS: { value: Fila; label: string }[] = [
    { value: "todos", label: "Por decidir" }, { value: "por_identificar", label: "Por identificar" }, { value: "administrador", label: "Enviados ao administrador" },
];

export default function UnitGuidePage() {
    const companyId = useWorkingCompanyId();
    const [canEdit, editReason] = useCan("restauracao.editar");
    const [params, setParams] = useSearchParams();
    const fila = (params.get("fila") as Fila) || "todos";
    const artigo = params.get("artigo");
    // U9: a correção guiada de uma unidade global (a unidade certa, depois os artigos "1 de N").
    const unidade = params.get("unidade");
    const [globalView, setGlobalView] = useState<GlobalUnitView | null>(null);
    const [queue, setQueue] = useState<UnitQueue | null>(null);
    const [view, setView] = useState<UnitGuideView | null>(null);
    const [answers, setAnswers] = useState<Record<string, string | null>>({});
    const [previewing, setPreviewing] = useState(false);
    const [loadingView, setLoadingView] = useState(false);
    const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);
    const edited = useRef(false);
    // "Como funciona": abre na primeira visita (e no botão "?").
    const [helpOpen, setHelpOpen] = useState(() => { try { return !localStorage.getItem(HELP_KEY); } catch { return false; } });
    const closeHelp = () => { setHelpOpen(false); try { localStorage.setItem(HELP_KEY, "1"); } catch { /* sem armazenamento */ } };
    // "Confirmar todos os que estão certos": a lista para rever, a confirmação e o "Desfazer".
    const [bulkOpen, setBulkOpen] = useState(false);
    const [bulkPick, setBulkPick] = useState<Record<string, boolean>>({});
    const [bulkBusy, setBulkBusy] = useState(false);
    const [lastBatch, setLastBatch] = useState<{ batch: string; n: number } | null>(null);

    const loadQueue = useCallback(async () => {
        if (!companyId) return;
        try {
            if (unidade) {
                const res: any = await getGlobalUnitGuide(companyId, unidade);
                const g: GlobalUnitView = res?.data;
                setGlobalView(g ?? null);
                const items: UnitQueueItem[] = (g?.articles ?? []).map((a) => ({
                    verdict: (a.done ? "certo" : "mudar") as UnitVerdictKey, verdict_text: a.done ? "Já passou para a unidade certa." : "Passar para a unidade certa.",
                    product_id: a.product_id, label: a.label, family: "", spend_90d_eur: a.purchases_cents === null ? null : a.purchases_cents / 100,
                    state: a.done ? "confirmado" : "sugerido", por_identificar: false, confidence: 1, findings: 0, impact_cents: a.impact_cents, pending_cents: null,
                    assigned: false, skipped: false,
                }));
                setQueue({ items, total: items.length, counts: { por_identificar: 0, administrador: 0 }, financial: g?.financial });
                return;
            }
            setGlobalView(null);
            const res: any = await getUnitGuideQueue(companyId, fila);
            setQueue(res?.data ?? null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível abrir o Guião de unidades.");
        }
    }, [companyId, fila, unidade]);
    useEffect(() => { loadQueue(); }, [loadQueue]);

    const items = queue?.items ?? [];
    const current = artigo ?? items[0]?.product_id ?? null;
    const index = current ? items.findIndex((x) => x.product_id === current) : -1;

    const loadView = useCallback(async (pid: string) => {
        if (!companyId) return;
        setLoadingView(true);
        try {
            const res: any = await getUnitGuide(companyId, pid);
            setView(res?.data ?? null);
            setAnswers(res?.data?.answers ?? {});
            edited.current = false;
        } catch (e: any) {
            setView(null);
            toast.error(e?.message ?? "Não foi possível abrir o artigo.");
        } finally {
            setLoadingView(false);
        }
    }, [companyId]);
    useEffect(() => { if (current) loadView(current); }, [current, loadView]);

    // As respostas refazem a proposta e o "O que muda" (sem gravar).
    const changeAnswers = (a: Record<string, string | null>) => {
        setAnswers(a);
        edited.current = true;
        if (debounce.current) clearTimeout(debounce.current);
        debounce.current = setTimeout(async () => {
            if (!companyId || !current) return;
            setPreviewing(true);
            try {
                const res: any = await previewUnitGuide(companyId, current, a);
                setView(res?.data ?? null);
            } catch { /* a proposta anterior fica */ } finally {
                setPreviewing(false);
            }
        }, 350);
    };

    const go = (pid: string | null) => {
        const next = new URLSearchParams(params);
        if (pid) next.set("artigo", pid); else next.delete("artigo");
        setParams(next, { replace: false });
    };
    const goNext = () => go(items[index + 1]?.product_id ?? items[0]?.product_id ?? null);
    const skip = async () => {
        if (!companyId || !current) return;
        try {
            await skipUnitGuide(companyId, current);
            toast.info("Saltado: volta ao fim da fila.");
            const nextPid = items[index + 1]?.product_id ?? null;
            await loadQueue();
            go(nextPid);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível saltar.");
        }
    };
    const changed = (v?: UnitGuideView) => {
        if (v) { setView(v); setAnswers(v.answers ?? {}); } else if (current) loadView(current);
        loadQueue();
    };
    const hidden = queue?.financial?.visible === false;
    const certos = items.filter((x) => x.verdict === "certo" && x.state !== "confirmado");
    const openBulk = () => { setBulkPick(Object.fromEntries(certos.map((x) => [x.product_id, true]))); setBulkOpen(true); };
    const confirmAll = async () => {
        if (!companyId) return;
        const ids = Object.keys(bulkPick).filter((k) => bulkPick[k]);
        setBulkBusy(true);
        try {
            const res: any = await confirmAllUnitGuide(companyId, ids);
            const n = res?.data?.confirmed?.length ?? 0;
            setLastBatch({ batch: res?.data?.batch, n });
            toast.success(res?.message ?? `${n} artigos confirmados na XPLENDOR.`);
            setBulkOpen(false);
            await loadQueue();
            if (current) loadView(current);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível confirmar.");
        } finally {
            setBulkBusy(false);
        }
    };
    const undo = async () => {
        if (!companyId || !lastBatch) return;
        try {
            const res: any = await undoUnitGuideBatch(companyId, lastBatch.batch);
            toast.success(`Desfeito: ${res?.data?.undone?.length ?? 0} artigos voltaram ao que eram.`);
            setLastBatch(null);
            await loadQueue();
            if (current) loadView(current);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível desfazer.");
        }
    };

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader title="Guião de unidades" breadcrumbs={[{ label: "Restauração" }, { label: "Auditoria" }]}
                    info="Artigo a artigo, pelo que se comprou nos últimos 90 dias: o que vimos, as perguntas, a proposta (unidade base, unidades de compra com o fator por fornecedor, unidade de contagem) e o que muda. Confirmar grava só na XPLENDOR; o PingWin só muda em «Aplicar no PingWin», uma alteração de cada vez." />

                <div className="d-flex align-items-start gap-2 mb-3" data-testid="guide-intro">
                    <p className="fs-15 mb-0 flex-grow-1">{HOW}</p>
                    <Button size="sm" color="light" className="rounded-circle flex-shrink-0" aria-label="Como funciona" onClick={() => setHelpOpen(true)} data-testid="guide-help-open">?</Button>
                </div>
                {lastBatch && (
                    <Alert color="success" className="d-flex flex-wrap align-items-center gap-2 py-2 fs-13" data-testid="guide-undo">
                        <span className="flex-grow-1">{lastBatch.n} {lastBatch.n === 1 ? "artigo confirmado" : "artigos confirmados"} em bloco, só na XPLENDOR. Ficou no histórico de cada artigo.</span>
                        <Button size="sm" color="light" onClick={undo}>Desfazer</Button>
                    </Alert>
                )}
                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3" data-testid="guide-header">
                    <div className="xp-seg" role="radiogroup" aria-label="Fila">
                        {FILAS.map((f) => (
                            <button key={f.value} type="button" role="radio" aria-checked={fila === f.value} className={fila === f.value ? "on" : ""}
                                onClick={() => { const n = new URLSearchParams(params); n.set("fila", f.value); n.delete("artigo"); setParams(n); }}>
                                {f.label}{f.value !== "todos" && queue ? ` (${queue.counts[f.value]})` : ""}
                            </button>
                        ))}
                    </div>
                    <AuditButton companyId={companyId} area="restauracao" origin="unidades" onFinished={() => { loadQueue(); if (current) loadView(current); }} />
                </div>
                {hidden && <FinancialNote className="mb-3" />}
                {unidade && globalView && companyId && (
                    <>
                        <Button size="sm" color="link" className="px-0 mb-2" onClick={() => { const n = new URLSearchParams(params); n.delete("unidade"); n.delete("artigo"); setParams(n); }}>
                            <i className="ri-arrow-left-line" aria-hidden /> Voltar à fila
                        </Button>
                        <GlobalUnitCard companyId={companyId} view={globalView} canEdit={canEdit} editReason={canEdit ? null : editReason} onChanged={() => { loadQueue(); if (current) loadView(current); }} />
                    </>
                )}

                {!queue ? <Skeleton variant="lines" lines={6} label="A preparar a fila…" /> : items.length === 0 ? (
                    <Card><CardBody className="text-center py-5 text-muted" data-testid="guide-empty">Não há artigos nesta fila.</CardBody></Card>
                ) : (
                    <Row className="g-3">
                        <Col xs={12} xl={4} className="order-2 order-xl-1">
                            <Card className="mb-0" data-testid="guide-queue">
                                <CardBody className="p-0">
                                    <div className="px-3 py-2 border-bottom fs-13 text-muted">{items.length} {items.length === 1 ? "artigo" : "artigos"}; à direita, o € comprado em 90 dias</div>
                                    <div style={{ maxHeight: 620, overflowY: "auto" }}>
                                        {GROUPS.map((g) => {
                                            const list = items.filter((x) => x.verdict === g.key);
                                            if (!list.length) return null;
                                            return (
                                                <div key={g.key} data-testid={`guide-group-${g.key}`}>
                                                    <div className="xp-guide-queue-group px-3 py-2 border-bottom d-flex flex-wrap align-items-center gap-2">
                                                        <span className={g.tone === "grey" ? "badge bg-body-tertiary text-body border" : `badge bg-${g.tone}-subtle text-${g.tone}`}>{g.label}</span>
                                                        <span className="fs-12 text-muted">{list.length}</span>
                                                        {g.key === "certo" && !unidade && certos.length > 0 && (
                                                            <Button size="sm" color="success" className="ms-auto py-0" onClick={openBulk} disabled={!canEdit} data-testid="guide-confirm-all">
                                                                Confirmar todos os que estão certos
                                                            </Button>
                                                        )}
                                                    </div>
                                                    <div className="list-group list-group-flush">
                                                        {list.map((x) => (
                                                            <button key={x.product_id} type="button" onClick={() => go(x.product_id)} title={x.verdict_text}
                                                                className={`list-group-item list-group-item-action d-flex justify-content-between align-items-start gap-2 ${x.product_id === current ? "active" : ""}`}>
                                                                <span className="text-truncate d-flex align-items-start gap-2">
                                                                    <span className={`rounded-circle ${g.tone === "grey" ? "xp-guide-dot-grey" : `bg-${g.tone}`} flex-shrink-0 mt-1`} style={{ width: 8, height: 8 }} aria-hidden />
                                                                    <span className="text-truncate">
                                                                        <span className="d-block text-truncate">{x.label}</span>
                                                                        <span className="fs-12 opacity-75 d-block text-truncate">{unidade ? x.verdict_text : x.state === "confirmado" ? "Confirmado" : x.verdict_text}{x.skipped ? " · saltado" : ""}{x.assigned ? " · administrador" : ""}</span>
                                                                    </span>
                                                                </span>
                                                                <span className="fs-12 text-nowrap text-end">
                                                                    {hidden ? <HiddenMoney seed={1} /> : eur0(x.spend_90d_eur)}
                                                                    <span className="d-block opacity-75">comprado em 90 dias</span>
                                                                </span>
                                                            </button>
                                                        ))}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </CardBody>
                            </Card>
                        </Col>
                        <Col xs={12} xl={8} className="order-1 order-xl-2">
                            <div className="d-flex align-items-center gap-2 mb-3" data-testid="guide-nav">
                                <Button size="sm" color="light" onClick={() => go(items[index - 1]?.product_id ?? null)} disabled={index <= 0}><i className="ri-arrow-left-line" aria-hidden /> Anterior</Button>
                                <span className="fs-13" data-testid="guide-position">{index >= 0 ? index + 1 : "?"} de {items.length}</span>
                                <Button size="sm" color="light" onClick={goNext} disabled={index >= items.length - 1}>Seguinte <i className="ri-arrow-right-line" aria-hidden /></Button>
                                {loadingView && <Spinner size="sm" />}
                            </div>
                            {!view ? <Skeleton variant="lines" lines={8} label="A abrir o artigo…" /> : companyId && (
                                <UnitGuideArticle companyId={companyId} view={view} answers={answers} setAnswers={changeAnswers} previewing={previewing}
                                    canEdit={canEdit} editReason={canEdit ? null : editReason} onChanged={changed} onSkip={skip} onNext={goNext} />
                            )}
                        </Col>
                    </Row>
                )}
                <Modal isOpen={helpOpen} toggle={closeHelp} centered data-testid="guide-help">
                    <ModalHeader toggle={closeHelp}>Como funciona</ModalHeader>
                    <ModalBody className="fs-14">
                        <p>{HOW}</p>
                        <ul className="ps-3 mb-0">
                            <li className="mb-1">No topo de cada artigo está a conclusão: <strong className="text-success">Está certo</strong> (nada a mudar), <strong className="text-warning">Precisa de mudança</strong> (o que muda, numa frase) ou <strong>Precisamos da sua ajuda</strong> (faltam dados).</li>
                            <li className="mb-1">Em baixo, as evidências, com a fonte à vista: faturas, fichas, contagens e PingWin.</li>
                            <li className="mb-1">Confirmar grava só na XPLENDOR. O PingWin só muda em «Aplicar no PingWin», uma alteração de cada vez, com releitura.</li>
                            <li>Os artigos que estão certos podem confirmar-se todos de uma vez, e desfazer-se.</li>
                        </ul>
                    </ModalBody>
                    <ModalFooter><Button color="primary" onClick={closeHelp}>Percebi</Button></ModalFooter>
                </Modal>
                <Modal isOpen={bulkOpen} toggle={() => !bulkBusy && setBulkOpen(false)} centered scrollable data-testid="guide-bulk">
                    <ModalHeader toggle={() => !bulkBusy && setBulkOpen(false)}>Confirmar os que estão certos</ModalHeader>
                    <ModalBody>
                        <p className="fs-13">Reveja a lista. Confirmar grava só na XPLENDOR (nada no PingWin), fica no histórico de cada artigo e pode desfazer-se a seguir.</p>
                        <div className="d-flex flex-column gap-1">
                            {certos.map((x) => (
                                <div key={x.product_id} className="form-check">
                                    <Input type="checkbox" className="form-check-input" id={`bulk-${x.product_id}`} checked={!!bulkPick[x.product_id]}
                                        onChange={(e) => setBulkPick({ ...bulkPick, [x.product_id]: e.target.checked })} />
                                    <Label className="form-check-label" for={`bulk-${x.product_id}`}>
                                        {x.label} <span className="text-muted fs-12">· {x.verdict_text}</span>
                                    </Label>
                                </div>
                            ))}
                        </div>
                    </ModalBody>
                    <ModalFooter>
                        <Badge color="light" className="text-body me-auto">{Object.values(bulkPick).filter(Boolean).length} de {certos.length}</Badge>
                        <Button color="light" onClick={() => setBulkOpen(false)} disabled={bulkBusy}>Cancelar</Button>
                        <Button color="success" onClick={confirmAll} disabled={bulkBusy || !Object.values(bulkPick).some(Boolean)} data-testid="guide-bulk-confirm">
                            {bulkBusy && <Spinner size="sm" className="me-1" />}Confirmar na XPLENDOR
                        </Button>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
}
