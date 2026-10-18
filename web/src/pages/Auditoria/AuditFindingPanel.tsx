import { useCallback, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Button, Input, Label, Offcanvas, OffcanvasBody, OffcanvasHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import Skeleton from "Components/Common/Skeleton";
import ReasonButton from "Components/Common/ReasonButton";
import { FinancialNote } from "Components/Common/HiddenMoney";
import { getAuditFinding, getRecipe, getRecipeConversionInfo, setArticleGroupMain, setAuditFindingState } from "helpers/laravel_helper";
import { AuditFindingDetail } from "common/models/audit.model";
import ConfirmFactorModal, { FactorLine } from "pages/Restauracao/fichas/ConfirmFactorModal";
import ConfirmYieldModal from "pages/Restauracao/fichas/ConfirmYieldModal";
import { RecipeVersion } from "common/models/recipe.model";
import SubstituteModal from "./SubstituteModal";
import PriceHistoryModal from "./PriceHistoryModal";
import DocumentsSideBySideModal from "./DocumentsSideBySideModal";
import PriceSimulator from "./PriceSimulator";
import { AuditStateBadge, Cents, ENTITY_LABEL, EvidenceView, SeverityBadge, STATE, fmtWhen } from "./auditFormat";
import "./auditoria.css";

/**
 * XPLENDOR — Auditoria: o detalhe de um achado num painel lateral, sempre com quatro blocos, nesta
 * ordem: O que vimos, Porque importa, O que fazer e Quanto está em jogo. As ações: Corrigir (abre o
 * sítio certo), Aceitar, Ignorar (com motivo), Marcar como corrigido (a auditoria seguinte confirma)
 * e Reabrir. A auditoria nunca corrige nada sozinha.
 */
const LOOK_ONLY = ["historico_precos", "comparar_fornecedores", "documentos_lado_a_lado", "simular_preco"];

export default function AuditFindingPanel({ companyId, findingId, onClose, onChanged }: {
    companyId: number; findingId: number; onClose: () => void; onChanged: () => void;
}) {
    const navigate = useNavigate();
    const [f, setF] = useState<AuditFindingDetail | null>(null);
    const [hidden, setHidden] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const [ignoring, setIgnoring] = useState(false);
    const [reason, setReason] = useState("");
    const [factorLine, setFactorLine] = useState<FactorLine | null>(null);
    const [main, setMain] = useState<string>("");
    // Auditoria v2: o substituto do M2 e o rendimento já preenchido.
    const [substitute, setSubstitute] = useState<{ productId: string; componentId: string } | null>(null);
    const [yieldFix, setYieldFix] = useState<{ productId: string; version: RecipeVersion; quantity: number | null; reason: string | null } | null>(null);
    // Auditoria v2-C (Dinheiro): o histórico de preços (ou a comparação) e os dois documentos lado a lado.
    const [prices, setPrices] = useState<{ productId: string; products?: string[]; tab: "historico" | "comparar" } | null>(null);
    const [sideBySide, setSideBySide] = useState<string[] | null>(null);

    const load = useCallback(async () => {
        try {
            const res: any = await getAuditFinding(companyId, findingId);
            setF(res?.data?.finding ?? null);
            setHidden(res?.data?.financial?.visible === false);
            setMain(res?.data?.finding?.evidence?.group?.main ?? "");
        } catch {
            toast.error("Não foi possível abrir o achado.");
            onClose();
        }
    }, [companyId, findingId, onClose]);
    useEffect(() => { setF(null); setIgnoring(false); setReason(""); load(); }, [load]);

    const act = async (action: "aceitar" | "ignorar" | "corrigido" | "reabrir") => {
        if (!f) return;
        setBusy(action);
        try {
            const res: any = await setAuditFindingState(companyId, f.area, f.id, action, action === "ignorar" ? reason : undefined);
            setF(res?.data?.finding ?? f);
            setIgnoring(false);
            setReason("");
            toast.success({ aceitar: "Aceite: fica na lista até estar corrigido.", ignorar: "Ignorado. Volta a aparecer se os dados mudarem.", corrigido: "Marcado como corrigido: a próxima auditoria confirma se ficou resolvido.", reabrir: "Reaberto." }[action]);
            onChanged();
        } catch (e: any) {
            toast.error(e?.errors?.reason?.[0] ?? e?.errors?.action?.[0] ?? e?.message ?? "Não foi possível mudar o estado.");
        } finally {
            setBusy(null);
        }
    };

    /** Depois de corrigir no sítio certo: o achado fica "Marcado como corrigido" (a corrida seguinte confirma). */
    const fixed = async (message: string) => {
        if (!f) return;
        try {
            if (["aberto", "aceite"].includes(f.state) && f.can_edit) {
                const res: any = await setAuditFindingState(companyId, f.area, f.id, "corrigido");
                setF(res?.data?.finding ?? f);
            }
            toast.success(message);
            onChanged();
        } catch {
            toast.success(message);
        }
    };

    const fix = async () => {
        if (!f) return;
        const a = f.fix.action;
        if (a?.type === "guiao") {
            onClose();
            navigate(`/restauracao/auditoria/guiao?artigo=${encodeURIComponent(a.product_id)}`);
            return;
        }
        if (a?.type === "unidade_global") {
            onClose();
            navigate(`/restauracao/auditoria/guiao?unidade=${encodeURIComponent(a.unit_id)}`);
            return;
        }
        if (a?.type === "historico_precos") {
            setPrices({ productId: a.product_id, tab: "historico" });
            return;
        }
        if (a?.type === "comparar_fornecedores") {
            setPrices({ productId: a.product_id, products: a.products, tab: "comparar" });
            return;
        }
        if (a?.type === "documentos_lado_a_lado") {
            setSideBySide(a.documents);
            return;
        }
        if (a?.type === "substituir_componente") {
            setSubstitute({ productId: a.product_id, componentId: a.component_id });
            return;
        }
        if (a?.type === "confirmar_rendimento") {
            setBusy("fix");
            try {
                const res: any = await getRecipe(companyId, a.product_id);
                const versions: RecipeVersion[] = res?.data?.versions ?? [];
                const version = versions.find((v) => v.bom_id === a.bom_id) ?? versions.find((v: any) => v.in_force) ?? versions[0];
                if (version?.yield) setYieldFix({ productId: a.product_id, version, quantity: a.quantity, reason: a.reason });
                else if (f.fix.url) { onClose(); navigate(f.fix.url); }
            } catch (e: any) {
                toast.error(e?.message ?? "Não foi possível abrir a ficha.");
            } finally {
                setBusy(null);
            }
            return;
        }
        if (a?.type === "confirmar_fator") {
            setBusy("fix");
            try {
                const res: any = await getRecipeConversionInfo(companyId, a.product_id, a.unit_key);
                setFactorLine(res?.data?.line ?? null);
            } catch (e: any) {
                toast.error(e?.message ?? "Não foi possível abrir a confirmação da embalagem.");
            } finally {
                setBusy(null);
            }
            return;
        }
        if (f.fix.url) {
            onClose();
            navigate(f.fix.url);
        }
    };

    const saveMain = async () => {
        if (!f || !main) return;
        setBusy("main");
        try {
            const res: any = await setArticleGroupMain(companyId, f.id, main);
            setF(res?.data?.finding ?? f);
            toast.success("Artigo principal escolhido: aparece com uma estrela quando procura ingredientes nas fichas.");
            onChanged();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível escolher o artigo principal.");
        } finally {
            setBusy(null);
        }
    };

    const noEdit = f && !f.can_edit ? "Precisa da permissão de editar desta área para mudar o estado." : null;
    const isGroup = f?.fix.action?.type === "escolher_principal";
    const members = isGroup ? (f?.evidence.table?.rows ?? []) : [];

    return (
        <Offcanvas isOpen toggle={onClose} direction="end" scrollable className="xp-audit-panel" data-testid="audit-finding-panel">
            <OffcanvasHeader toggle={onClose} className="border-bottom align-items-start">
                {f ? (
                    <div>
                        <div className="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <SeverityBadge severity={f.severity} />
                            <AuditStateBadge state={f.state} />
                            <span className="badge bg-light text-body">{f.area_label}</span>
                            {f.note && <span className="badge bg-warning-subtle text-warning">{f.note}</span>}
                        </div>
                        <div className="fs-16">{f.title}</div>
                        <div className="fs-12 text-muted fw-normal">{ENTITY_LABEL[f.entity_type] ?? f.entity_type}: {f.entity_label} · {f.rule_title}</div>
                    </div>
                ) : "Achado"}
            </OffcanvasHeader>
            <OffcanvasBody>
                {!f ? <Skeleton variant="lines" lines={6} label="A abrir o achado…" /> : (
                    <>
                        {hidden && <FinancialNote className="mb-3" />}
                        <div className="d-flex flex-wrap gap-2 mb-3" data-testid="audit-actions">
                            {(f.fix.url || f.fix.action) && (
                                <Button type="button" color="primary" onClick={fix} disabled={busy !== null} data-testid="audit-fix">
                                    {busy === "fix" ? <Spinner size="sm" className="me-1" /> : <i className={`${LOOK_ONLY.includes(f.fix.action?.type ?? "") ? "ri-line-chart-line" : "ri-tools-line"} me-1`} aria-hidden />}
                                    {/* As ações de dinheiro só mostram (histórico, comparar, lado a lado, engenharia de menu): sem "Corrigir". */}
                                    {LOOK_ONLY.includes(f.fix.action?.type ?? "") && f.fix.label ? f.fix.label : <>Corrigir{f.fix.label ? `: ${f.fix.label.toLowerCase()}` : ""}</>}
                                </Button>
                            )}
                            {f.state === "aberto" && (
                                <ReasonButton type="button" color="outline-primary" reason={noEdit} onClick={() => act("aceitar")} disabled={busy !== null}>Aceitar</ReasonButton>
                            )}
                            {["aberto", "aceite"].includes(f.state) && (
                                <ReasonButton type="button" color="outline-success" reason={noEdit} onClick={() => act("corrigido")} disabled={busy !== null}>Marcar como corrigido</ReasonButton>
                            )}
                            {["aberto", "aceite"].includes(f.state) && !ignoring && (
                                <ReasonButton type="button" color="outline-secondary" reason={noEdit} onClick={() => setIgnoring(true)} disabled={busy !== null}>Ignorar</ReasonButton>
                            )}
                            {["aceite", "ignorado", "corrigido"].includes(f.state) && (
                                <ReasonButton type="button" color="light" reason={noEdit} onClick={() => act("reabrir")} disabled={busy !== null}>Reabrir</ReasonButton>
                            )}
                        </div>
                        {ignoring && (
                            <div className="border rounded p-3 mb-3 bg-light-subtle" data-testid="audit-ignore">
                                <Label for="audit-ignore-reason" className="form-label">Porque ignora este achado?</Label>
                                <Input id="audit-ignore-reason" type="textarea" rows={2} value={reason} onChange={(e) => setReason(e.target.value)}
                                    placeholder="Ex.: está certo assim, combinado com o contabilista." />
                                <div className="text-muted fs-12 mt-1">Volta a aparecer sozinho se os dados mudarem.</div>
                                <div className="d-flex gap-2 mt-2">
                                    <Button type="button" color="light" onClick={() => { setIgnoring(false); setReason(""); }}>Cancelar</Button>
                                    <Button type="button" color="secondary" onClick={() => act("ignorar")} disabled={!reason.trim() || busy !== null}>Ignorar</Button>
                                </div>
                            </div>
                        )}
                        {f.state === "corrigido" && <div className="alert alert-info py-2 fs-13">Marcado como corrigido{f.state_by ? ` por ${f.state_by}` : ""}: a próxima auditoria confirma. Se já não o encontrar, passa a Resolvido.</div>}
                        {f.state === "ignorado" && f.state_reason && <div className="alert alert-secondary py-2 fs-13">Ignorado{f.state_by ? ` por ${f.state_by}` : ""}: {f.state_reason}</div>}

                        {/* Os quatro blocos, sempre nesta ordem (a simulação do preço da C2 vem antes, com o "Corrigir"). */}
                        {f.fix.action?.type === "simular_preco" && !hidden && <PriceSimulator action={f.fix.action} />}
                        <section className="mb-3" data-testid="audit-block-seen">
                            <h6 className="fs-13 text-uppercase text-muted mb-1">O que vimos</h6>
                            <p className="mb-2">{f.seen}</p>
                            {(f.evidence.facts?.length || f.evidence.table?.rows?.length) ? (
                                <details className="fs-13">
                                    <summary className="text-primary" style={{ cursor: "pointer" }}>Ver os pormenores</summary>
                                    <div className="mt-2"><EvidenceView evidence={f.evidence} hidden={hidden} /></div>
                                </details>
                            ) : null}
                        </section>
                        <section className="mb-3" data-testid="audit-block-why">
                            <h6 className="fs-13 text-uppercase text-muted mb-1">Porque importa</h6>
                            <p className="mb-0">{f.why}</p>
                        </section>
                        <section className="mb-3" data-testid="audit-block-todo">
                            <h6 className="fs-13 text-uppercase text-muted mb-1">O que fazer</h6>
                            <p className="mb-0">{f.suggestion}</p>
                        </section>
                        <section className="mb-3" data-testid="audit-block-stake">
                            <h6 className="fs-13 text-uppercase text-muted mb-1">Quanto está em jogo</h6>
                            <p className="mb-2">{f.stake}</p>
                            {(f.impact_cents !== null || f.impact_pending_cents !== null || f.weight_cents !== null || hidden) && (
                                <div className="d-flex flex-wrap gap-4">
                                    {(f.impact_cents !== null || hidden) && <div><div className="text-muted fs-12">Em jogo</div><div className="fw-semibold"><Cents value={f.impact_cents} hidden={hidden} seed={1} /></div></div>}
                                    {(f.impact_pending_cents !== null || (hidden && f.impact_cents === null)) && (
                                        <div><div className="text-muted fs-12">A confirmar</div><div className="fw-semibold text-muted"><Cents value={f.impact_pending_cents} hidden={hidden} seed={3} /></div></div>
                                    )}
                                    {(f.weight_cents !== null || hidden) && <div><div className="text-muted fs-12">Relevância (vendas ou compras ligadas)</div><div className="fw-semibold text-muted"><Cents value={f.weight_cents} hidden={hidden} seed={2} /></div></div>}
                                </div>
                            )}
                        </section>

                        {isGroup && (
                            <section className="mb-3 border rounded p-3" data-testid="audit-main-choice">
                                <h6 className="fs-13 text-uppercase text-muted mb-2">Escolher artigo principal</h6>
                                <div className="d-flex flex-column gap-1">
                                    {members.map((m: any) => (
                                        <div key={m.product_id} className="form-check">
                                            <Input type="radio" className="form-check-input" name="audit-main" id={`main-${m.product_id}`} checked={main === m.product_id}
                                                onChange={() => setMain(m.product_id)} disabled={!f.can_edit} />
                                            <Label className="form-check-label" for={`main-${m.product_id}`}>{m.code ? `${m.code} · ` : ""}{m.description}</Label>
                                        </div>
                                    ))}
                                </div>
                                <div className="text-muted fs-12 mt-1">Fica só na XPLENDOR (o PingWin não muda): o principal aparece com uma estrela quando procura ingredientes nas fichas.</div>
                                <ReasonButton type="button" size="sm" color="primary" className="mt-2" reason={noEdit ?? (!main ? "Escolha um artigo." : null)} onClick={saveMain} disabled={busy !== null}>
                                    {busy === "main" ? <Spinner size="sm" className="me-1" /> : <i className="ri-star-line me-1" aria-hidden />}Guardar o artigo principal
                                </ReasonButton>
                            </section>
                        )}

                        <section>
                            <h6 className="fs-13 text-uppercase text-muted mb-2">Histórico</h6>
                            <ul className="list-unstyled fs-13 mb-0">
                                {f.events.map((e, i) => (
                                    <li key={i} className="py-1 border-bottom border-light-subtle">
                                        <span className="text-muted me-2">{fmtWhen(e.at)}</span>
                                        {e.from ? <>{STATE[e.from]?.label ?? e.from} → </> : null}<strong>{STATE[e.to]?.label ?? e.to}</strong>
                                        <span className="text-muted"> · {e.user ?? "auditoria"}</span>
                                        {e.reason && <div className="text-muted">{e.reason}</div>}
                                    </li>
                                ))}
                            </ul>
                            <div className="text-muted fs-12 mt-2">Encontrado a {fmtWhen(f.first_seen_at)}. Visto pela última vez a {fmtWhen(f.last_seen_at)}.</div>
                        </section>
                    </>
                )}
            </OffcanvasBody>
            {substitute && (
                <SubstituteModal companyId={companyId} productId={substitute.productId} componentId={substitute.componentId}
                    onClose={() => setSubstitute(null)}
                    onSaved={() => { setSubstitute(null); fixed("Ficha gravada no PingWin. O achado ficou marcado como corrigido: a próxima auditoria confirma."); }} />
            )}
            {yieldFix && (
                <ConfirmYieldModal open companyId={companyId} productId={yieldFix.productId} version={yieldFix.version} hidden={hidden}
                    suggested={{ quantity: yieldFix.quantity, reason: yieldFix.reason }}
                    onClose={() => setYieldFix(null)}
                    onDone={() => { setYieldFix(null); fixed("Rendimento confirmado. O achado ficou marcado como corrigido: a próxima auditoria confirma."); }} />
            )}
            {factorLine?.conversion && (
                <ConfirmFactorModal open line={factorLine} companyId={companyId} hidden={hidden}
                    onClose={() => setFactorLine(null)}
                    onDone={() => { setFactorLine(null); toast.success("Embalagem confirmada. Marque o achado como corrigido: a próxima auditoria confirma."); load(); }} />
            )}
            {prices && <PriceHistoryModal companyId={companyId} productId={prices.productId} products={prices.products} tab={prices.tab} onClose={() => setPrices(null)} />}
            {sideBySide && <DocumentsSideBySideModal companyId={companyId} documents={sideBySide} onClose={() => setSideBySide(null)} />}
        </Offcanvas>
    );
}
