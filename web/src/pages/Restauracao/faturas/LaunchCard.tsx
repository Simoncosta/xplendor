import { useCallback, useEffect, useRef, useState } from "react";
import { Alert, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { Link } from "react-router-dom";
import PageCard from "Components/Common/PageCard";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import ActionsMenu from "Components/Common/ActionsMenu";
import { closeOcrLaunch, getArticleRead, getOcrLaunch, launchOcrInvoice, refreshArticle, setOcrLineLaunchUnit, setOcrPaperCheck, voidOcrLaunch } from "helpers/laravel_helper";
import { OcrGuideArticle, OcrLaunchPreview } from "common/models/ocr.model";
import { UNIT_GUIDE, unitGuideLink } from "../units/guideLink";
import { useArticleReadPolling } from "../useArticleReadPolling";

/**
 * XPLENDOR — FB-1: "Lançar no PingWin". "Gravar como rascunho" cria a Fatura de fornecedor em
 * RASCUNHO (Aberto: não mexe no stock nem na conta corrente); "Gravar como fechada" lança e fecha
 * (só com todas as validações a verde e as unidades das linhas confirmadas no Guião de unidades).
 * Um rascunho pode ser fechado depois, ou anulado. Cada ação é uma escrita assíncrona confirmada por
 * releitura; o cartão acompanha-a (polling). Se um botão estiver desativado, diz o que falta.
 */

/** trigger: um pedido de fora (o painel "Fatura validada") para abrir a confirmação de lançar. */
export type LaunchTrigger = { action: "draft" | "closed"; at: number } | null;
/** onPreview: a última pré-visualização, para o painel "Fatura validada" usar as mesmas guardas. */
type Props = { companyId: number; invoiceId: number; onChanged: () => void; trigger?: LaunchTrigger; refreshKey?: number; onPreview?: (p: OcrLaunchPreview) => void };

export const CLOSE_WARNING = "Depois de fechada, a fatura entra no stock e na conta corrente. Para corrigir, é preciso anular.";
const ARTICLES = "/cadastros/artigos";

export type LaunchFix = { to: string; label: string };
/** O que os dois botões de gravar dizem (null = ativo) e onde se resolve o que falta. */
export type LaunchState = { draft: string | null; closed: string | null; fixes: LaunchFix[] };

/** Os artigos das linhas com a unidade por resolver (a guarda) ou por confirmar no Guião (o fecho), sem repetir. */
export function unitGuideArticles(p: OcrLaunchPreview): OcrGuideArticle[] {
    const all = [...(p.guards.find((g) => g.key === "unidades" && !g.ok)?.articles ?? []),
        ...(p.close?.blockers.find((b) => b.key === "unidades_confirmadas")?.articles ?? [])];
    return all.filter((a, i) => all.findIndex((x) => x.pingwin_id === a.pingwin_id) === i);
}

/**
 * Os motivos de "Gravar como rascunho" e "Gravar como fechada" a partir da pré-visualização: os mesmos
 * no cartão e no painel "Fatura validada" (o ecrã nunca oferece o que o lançamento vai recusar).
 * backTo: o caminho da fatura, para o "Voltar à fatura" no Guião de unidades.
 */
export function launchState(p: OcrLaunchPreview, busyWrite = false, backTo?: string): LaunchState {
    const failed = p.guards.filter((g) => !g.ok);
    const onlyCheck = failed.length === 1 && failed[0].key === "conferencia";
    const pending = busyWrite || p.writes.some((w) => w.status === "pendente");
    // O motivo do tipo é o da guarda (a mensagem certa para cada tipo).
    const typeReason = failed.find((g) => g.key === "tipo")?.message ?? null;
    const launchReason = typeReason ?? (p.can_launch || onlyCheck ? null : failed.map((g) => g.message).join(" "));
    const blockers = p.close?.blockers ?? [];
    const closed = pending ? "Há uma escrita em curso." : typeReason ? typeReason
        : p.close?.can_close ? null : blockers.map((b) => b.message).join(" ") || "Ainda não se pode fechar.";
    const draft = pending ? "Há uma escrita em curso." : launchReason;

    const keys = new Set([...failed.map((g) => g.key), ...blockers.map((b) => b.key)]);
    const fixes: LaunchFix[] = [];
    const add = (f: LaunchFix) => { if (!fixes.some((x) => x.to === f.to)) fixes.push(f); };
    // Os artigos que o PingWin arredondava (unidade sem decimais) e os de agrupamento com taxas misturadas.
    const planned = p.planned?.lines ?? [];
    if (keys.has("unidades")) {
        planned.filter((l) => l.kind === "linha" && l.rounded_from != null && l.article)
            .forEach((l) => add({ to: `${ARTICLES}/${l.article!.pingwin_id}`, label: `Abrir ${l.article!.description ?? "o artigo"} em Cadastros › Artigos` }));
    }
    // Cada artigo com a unidade por resolver liga ao seu artigo no Guião (e volta à fatura).
    if (keys.has("unidades") || keys.has("unidades_confirmadas")) {
        const arts = unitGuideArticles(p);
        arts.forEach((a) => add({ to: unitGuideLink(a.pingwin_id, backTo), label: `Abrir ${a.description} no Guião de unidades` }));
        if (arts.length === 0) add({ to: UNIT_GUIDE, label: "Abrir o Guião de unidades" });
    }
    if (keys.has("agrupamento")) {
        planned.filter((l) => l.kind === "grupo" && l.article)
            .forEach((l) => add({ to: `${ARTICLES}/${l.article!.pingwin_id}`, label: `Abrir ${l.article!.description ?? "o artigo"} em Cadastros › Artigos` }));
    }
    if (keys.has("fornecedor")) add({ to: "/restauracao/fornecedores", label: "Abrir os fornecedores" });

    return { draft, closed, fixes: draft || closed ? fixes : [] };
}

const eur = (cents?: number | null) => ((cents ?? 0) / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR" });
const fmtDay = (d?: string | null) => (d ? d.split("-").reverse().join("/") : "—");
const ACTION_LABEL = { launch: "lançar", close: "fechar", void: "anular" } as const;

const eurC = (c: number) => (c / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR" });

/**
 * O que vai para o PingWin, quando há IEC numa coluna à parte: as linhas da fatura (preço e desconto
 * lidos, sem o IEC) e a linha do IEC, como nas faturas anteriores do fornecedor. Só leitura.
 */
function PlannedLines({ planned }: { planned: NonNullable<OcrLaunchPreview["planned"]> }) {
    return (
        <div className="mb-2" data-testid="launch-planned">
            <div className="fs-13 mb-1">
                <i className="ri-information-line me-1" />O IEC vai numa linha à parte{planned.iec_from ? `, como na ${planned.iec_from}` : ""}. O que vai para o PingWin:
            </div>
            <div className="table-responsive">
                <table className="table table-sm table-bordered align-middle mb-0 fs-13">
                    <caption className="visually-hidden">Linhas que vão para o PingWin</caption>
                    <thead className="table-light text-muted">
                        <tr><th>Linha</th><th className="text-end">Qtd</th><th className="text-end">Preço</th><th className="text-end">Desc.</th><th className="text-end">IVA</th><th className="text-end">Sem IVA</th><th className="text-end">Total</th></tr>
                    </thead>
                    <tbody>
                        {planned.lines.map((l, i) => (
                            <tr key={i} className={l.kind === "iec" ? "fw-semibold" : undefined} data-testid={`planned-${l.kind}-${i}`}>
                                <td>{l.article ? `${l.article.code ?? ""} ${l.article.description ?? ""}`.trim() : l.item}</td>
                                <td className="text-end">{l.qnt.toLocaleString("pt-PT")}</td>
                                <td className="text-end">{l.price.toLocaleString("pt-PT", { minimumFractionDigits: 2, maximumFractionDigits: 6 })}</td>
                                <td className="text-end">{l.discount_pct ? `${l.discount_pct.toLocaleString("pt-PT")} %` : "—"}</td>
                                <td className="text-end">{l.vat_rate} %</td>
                                <td className="text-end text-nowrap">{eurC(l.net_cents)}</td>
                                <td className="text-end text-nowrap">{eurC(l.total_cents)}</td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="fw-semibold"><td colSpan={6} className="text-end">Total do documento</td><td className="text-end text-nowrap" data-testid="planned-total">{eurC(planned.total_cents)}</td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    );
}

export default function LaunchCard({ companyId, invoiceId, onChanged, trigger = null, refreshKey = 0, onPreview }: Props) {
    const [p, setP] = useState<OcrLaunchPreview | null>(null);
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState<"launch" | "launch_closed" | "close" | "void" | null>(null);
    // "Gravar como fechada": depois do lançamento, espera pela escrita do fecho (a seguir, no mesmo job).
    const [awaitClose, setAwaitClose] = useState<number | null>(null);
    const [acceptDiff, setAcceptDiff] = useState(false);
    const [unitDraft, setUnitDraft] = useState<Record<number, { unit_id: string; quantity: string }>>({});
    const [refreshingArticle, setRefreshingArticle] = useState<string | null>(null);
    const { read } = useArticleReadPolling(companyId);

    const load = useCallback(async () => {
        try {
            const r: any = await getOcrLaunch(companyId, invoiceId);
            setP(r?.data ?? null);
        } catch { /* fica o que está */ }
    }, [companyId, invoiceId]);

    useEffect(() => { load(); }, [load, refreshKey]);
    // Ao voltar a esta janela (por exemplo depois de marcar um artigo de agrupamento noutro separador),
    // a pré-visualização relê-se: o ecrã nunca fica com guardas antigas.
    useEffect(() => {
        const onFocus = () => { void load(); };
        window.addEventListener("focus", onFocus);
        return () => window.removeEventListener("focus", onFocus);
    }, [load]);
    // Cada pré-visualização nova vai também para a página (o painel "Fatura validada").
    useEffect(() => { if (p) onPreview?.(p); }, [p, onPreview]);

    // Polling enquanto há uma escrita em curso; no fim avisa a página (F3 e lista).
    const pending = p?.writes.find((w) => w.status === "pendente");
    useEffect(() => {
        if (!pending && awaitClose === null) return;
        const t = setInterval(async () => {
            const r: any = await getOcrLaunch(companyId, invoiceId).catch(() => null);
            const np: OcrLaunchPreview | null = r?.data ?? null;
            if (!np) return;
            setP(np);
            if (awaitClose !== null) {
                const launch = np.writes.find((x) => x.id === awaitClose);
                const close = np.writes.find((x) => x.action === "close" && x.id > awaitClose);
                if (launch && launch.status !== "pendente" && launch.status !== "ok") {
                    toast.error(`Não foi possível lançar: ${launch.error ?? "erro"}`);
                    setAwaitClose(null);
                    onChanged();
                } else if (close && close.status !== "pendente") {
                    if (close.status === "ok") toast.success(`Gravada no PingWin como fechada: ${close.document}.`);
                    else toast.error(`Lançada em rascunho (${launch?.document ?? close.document}), mas não foi possível fechar: ${close.error ?? "erro"}`);
                    setAwaitClose(null);
                    onChanged();
                }
                return;
            }
            const w = pending ? np.writes.find((x) => x.id === pending.id) : null;
            if (w && w.status !== "pendente") {
                if (w.status === "ok") toast.success(w.action === "launch" ? `Lançada em rascunho: ${w.document}.` : w.action === "close" ? `Fechada no PingWin: ${w.document}.` : `Rascunho anulado: ${w.document}.`);
                else toast.error(`Não foi possível ${ACTION_LABEL[w.action]}: ${w.error ?? "erro"}`);
                onChanged();
            }
        }, 3000);
        return () => clearInterval(t);
    }, [pending, awaitClose, companyId, invoiceId, onChanged]);

    // Pedido de fora (o painel "Fatura validada"): abre a confirmação, ou diz o que falta.
    const reasonsRef = useRef<{ draft: string | null; closed: string | null }>({ draft: null, closed: null });
    useEffect(() => {
        if (!trigger || !p) return;
        const reason = trigger.action === "closed" ? reasonsRef.current.closed : reasonsRef.current.draft;
        if (reason) {
            toast.warning(reason);
            return;
        }
        setConfirm(trigger.action === "closed" ? (p.draft?.docstatus_id === "8001" ? "close" : "launch_closed") : "launch");
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [trigger]);

    if (!p) return null;

    const failed = p.guards.filter((g) => !g.ok);
    const onlyCheck = failed.length === 1 && failed[0].key === "conferencia";
    const draftActive = p.draft && p.draft.docstatus_id !== "8003";
    const last = p.writes[0];
    const back = `/restauracao/faturas/${invoiceId}`;
    const state = launchState(p, false, back);
    const launchReason = launchState({ ...p, writes: [] }).draft;
    const closeBlockers = p.close?.blockers ?? [];
    const closeReason = state.closed;
    reasonsRef.current = { draft: state.draft, closed: closeReason };
    const unitsMissing = closeBlockers.some((b) => b.key === "unidades_confirmadas");

    const run = async (fn: () => Promise<any>, closeAfter = false) => {
        setBusy(true);
        try {
            const r: any = await fn();
            if (r?.data) setP(r.data);
            if (closeAfter && r?.data?.write?.id) setAwaitClose(r.data.write.id);
            setConfirm(null);
            setAcceptDiff(false);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível concluir.");
            if (e?.errors?.failed) load();
        } finally {
            setBusy(false);
        }
    };

    // "Atualizar do PingWin" de um artigo da linha: lê-o ao vivo, grava na cópia e volta a ver as guardas.
    const refreshFromPingwin = async (pingwinId: string) => {
        setRefreshingArticle(pingwinId);
        const r = await read(() => refreshArticle(companyId, pingwinId), (t) => getArticleRead(companyId, t));
        setRefreshingArticle(null);
        if (r.status === "ready") {
            const changes: { label: string; from: string | null; to: string | null }[] = r.data?.changes ?? [];
            toast.success(changes.length ? `Atualizado do PingWin: ${changes.map((c) => `${c.label} ${c.from ?? "—"} → ${c.to ?? "—"}`).join("; ")}.` : "Atualizado do PingWin: nada mudou.");
            await load();
        } else {
            toast.error(r.error_message ?? "Não foi possível atualizar o artigo.");
        }
    };

    const saveUnit = async (lineId: number) => {
        const d = unitDraft[lineId];
        if (!d?.unit_id) return;
        await run(() => setOcrLineLaunchUnit(companyId, invoiceId, lineId, { unit_id: d.unit_id, quantity: d.quantity === "" ? null : Number(d.quantity) }));
    };

    // A escolha de unidades só interessa a uma fatura que se pode lançar (FT e ainda não lançada).
    const launchable = !failed.some((g) => g.key === "tipo" || g.key === "nao_lancada");
    // Também as linhas cuja unidade arredonda: pode escolher-se outra unidade do artigo.
    const needsUnit = launchable ? p.lines.filter((l) => (!l.unit_ok || l.unit_rounds) && l.article) : [];
    const adj = p.estimate.adjustment_cents;

    const actions = draftActive ? (
        <>
            {p.draft!.docstatus_id === "8001" && (
                <ReasonButton size="sm" color="primary" reason={closeReason} disabled={busy} onClick={() => setConfirm("close")} data-testid="close-draft">
                    <i className="ri-lock-line me-1" />Gravar como fechada
                </ReasonButton>
            )}
            <ActionsMenu size="sm" label="Mais ações do documento" disabled={busy || !!pending} items={[
                { label: p.draft!.docstatus_id === "8001" ? "Anular rascunho" : "Anular no PingWin", icon: "ri-close-circle-line", danger: true, onClick: () => setConfirm("void") },
            ]} />
        </>
    ) : (
        <>
            <ReasonButton size="sm" color="primary" reason={pending || awaitClose !== null ? "Há uma escrita em curso." : launchReason} disabled={busy} onClick={() => setConfirm("launch")}>
                <i className="ri-upload-cloud-2-line me-1" />Gravar como rascunho
            </ReasonButton>
            <ReasonButton size="sm" color="outline-primary" reason={awaitClose !== null ? "Há uma escrita em curso." : closeReason} disabled={busy} onClick={() => setConfirm("launch_closed")} data-testid="launch-closed">
                <i className="ri-lock-line me-1" />Gravar como fechada
            </ReasonButton>
        </>
    );

    return (
        <PageCard
            title="Lançar no PingWin"
            info="Cria no PingWin a fatura de fornecedor (ou, numa nota de crédito, a nota de crédito de fornecedor) em RASCUNHO (Aberto): não mexe no stock nem na conta corrente até ser fechada."
            status={draftActive
                ? <span className={`badge ${p.draft!.docstatus_id === "8001" ? "bg-info-subtle text-info" : "bg-success-subtle text-success"}`}>{p.draft!.document} · {p.draft!.docstatus_label}</span>
                : p.can_launch ? <span className="badge bg-success-subtle text-success">Pronta para lançar</span> : <span className="text-muted">{failed.length} {failed.length === 1 ? "coisa por resolver" : "coisas por resolver"}</span>}
            actions={actions}
            flush={false}
            data-testid="launch-card"
        >
            {(pending || awaitClose !== null) && (
                <div className="fs-13 mb-2" data-testid="launch-pending"><Spinner size="sm" className="me-1" />
                    {awaitClose !== null ? "A gravar no PingWin como fechada: primeiro o rascunho, depois o fecho… (confirmação por releitura)" : `A ${ACTION_LABEL[pending!.action]} no PingWin… (confirmação por releitura)`}
                </div>
            )}
            {last && last.status === "erro" && !pending && (
                <Alert color="danger" className="py-2 fs-13" data-testid="launch-error">
                    <strong>Não foi possível {ACTION_LABEL[last.action]}.</strong> {last.error} {last.action === "launch" && <span className="text-muted">Nada foi gravado no PingWin.</span>}
                </Alert>
            )}
            {last && last.status === "erro_confirmacao" && !pending && (
                <Alert color="warning" className="py-2 fs-13" data-testid="launch-confirm-error">
                    <strong>Reveja no PingWin.</strong> {last.error} {last.docheader_id && <>(documento {last.document ?? last.docheader_id})</>}
                </Alert>
            )}

            {draftActive ? (
                <div className="fs-13" data-testid="launch-draft">
                    {p.draft!.docstatus_id === "8001"
                        ? <p className="mb-1"><i className="ri-draft-line me-1" />Lançada em rascunho: <strong>{p.draft!.document}</strong> (Aberto). Ainda não mexe no stock nem na conta corrente.</p>
                        : <p className="mb-1" data-testid="launch-closed-info"><i className="ri-check-double-line me-1 text-success" />Lançada e fechada no PingWin: <strong>{p.draft!.document}</strong>.
                            {p.close?.closed_at && <span className="text-muted"> Fechada por {p.close.closed_by_name ?? "—"} a {new Date(p.close.closed_at).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" })}.</span>}</p>}
                    <p className="text-muted mb-0">Total {(p.draft!.total).toLocaleString("pt-PT", { style: "currency", currency: "EUR" })} · {p.draft!.store_name ?? "—"} · lançada a {fmtDay(p.draft!.doc_date)}</p>
                    {p.draft!.docstatus_id === "8001" && closeBlockers.length > 0 && <CloseBlockers blockers={closeBlockers} unitsMissing={unitsMissing} back={back} />}
                </div>
            ) : (
                <>
                    <ul className="list-unstyled mb-2 fs-13" data-testid="launch-guards">
                        {p.guards.map((g) => (
                            <li key={g.key} className="d-flex gap-2 mb-1">
                                <i className={g.ok ? "ri-checkbox-circle-line text-success" : "ri-close-circle-line text-danger"} aria-hidden />
                                <span>{g.label}{!g.ok && g.message && <span className="text-muted"> · {g.message}</span>}
                                    {!g.ok && (g.articles ?? []).length > 0 && <GuideLinks articles={g.articles!} back={back} />}</span>
                            </li>
                        ))}
                    </ul>
                    {p.paper_check && (
                        <div className="form-check mb-2 fs-13" data-testid="launch-paper-check">
                            <input className="form-check-input" type="checkbox" id={`paper-check-${invoiceId}`} checked={p.paper_check.checked} disabled={busy || !!pending}
                                onChange={(e) => run(() => setOcrPaperCheck(companyId, invoiceId, e.target.checked))} />
                            <label className="form-check-label" htmlFor={`paper-check-${invoiceId}`}>
                                Conferi os totais com o papel
                                <span className="text-muted d-block fs-12">
                                    {p.paper_check.checked && p.paper_check.at
                                        ? `Marcado por ${p.paper_check.by_name ?? "—"} a ${new Date(p.paper_check.at).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" })}.`
                                        : "Sem QR legível: confirme o total, o IVA e o NIF do adquirente na fatura em papel."}
                                </span>
                            </label>
                        </div>
                    )}
                    {p.can_launch && closeBlockers.length > 0 && <CloseBlockers blockers={closeBlockers} unitsMissing={unitsMissing} back={back} />}
                    {(p.discount_warnings ?? []).map((w, i) => (
                        <div key={i} className="fs-13 text-warning mb-1" data-testid="launch-discount-warning"><i className="ri-error-warning-line me-1" />{w}</div>
                    ))}
                    {p.planned && p.planned.lines.filter((l) => l.kind === "grupo").map((l, i) => (
                        <div key={`g${i}`} className="fs-13 mb-1" data-testid="launch-grouped">
                            <i className="ri-stack-line me-1" />{l.lines} {l.lines === 1 ? "linha junta-se" : "linhas juntam-se"} em {l.article?.description ?? l.item}: 1 × {eurC(l.net_cents)}
                        </div>
                    ))}
                    {p.planned && p.planned.lines.some((l) => l.kind === "iec") && <PlannedLines planned={p.planned} />}
                    {needsUnit.length > 0 && (
                        <div className="table-responsive">
                            <table className="table table-sm table-bordered align-middle mb-0 fs-13" data-testid="launch-units">
                                <caption className="visually-hidden">Linhas sem unidade ou com uma unidade que arredonda</caption>
                                <thead className="table-light text-muted"><tr><th>Linha</th><th>Na fatura</th><th style={{ minWidth: 180 }}>Unidade do artigo</th><th style={{ width: 110 }}>Quantidade</th><th /></tr></thead>
                                <tbody>
                                    {needsUnit.map((l) => {
                                        const d = unitDraft[l.id] ?? { unit_id: "", quantity: l.quantity !== null ? String(l.quantity) : "" };
                                        return (
                                            <tr key={l.id}>
                                                <td>{l.article?.code} {l.article?.description}
                                                    {l.unit_rounds && <span className="badge bg-warning-subtle text-warning ms-1" title="A unidade escolhida não aceita decimais: o PingWin arredonda a quantidade.">arredonda</span>}</td>
                                                <td>{l.quantity ?? "—"} {l.ocr_unit ?? "(sem unidade)"}</td>
                                                <td><XSelect small ariaLabel={`Unidade da linha ${l.position + 1}`} options={l.unit_options} value={d.unit_id} placeholder="Escolher…" searchable={false}
                                                    onChange={(v) => setUnitDraft((s) => ({ ...s, [l.id]: { ...d, unit_id: v } }))} /></td>
                                                <td><Input bsSize="sm" type="number" step="any" min={0} className="text-end" aria-label={`Quantidade da linha ${l.position + 1}`} value={d.quantity}
                                                    onChange={(e) => setUnitDraft((s) => ({ ...s, [l.id]: { ...d, quantity: e.target.value } }))} /></td>
                                                <td className="text-nowrap">
                                                    <ReasonButton size="sm" color="outline-primary" reason={!d.unit_id ? "Escolha a unidade." : null} disabled={busy} onClick={() => saveUnit(l.id)}>Guardar</ReasonButton>
                                                    <Link to={unitGuideLink(l.article!.pingwin_id, back)} className="btn btn-link btn-sm py-0" data-testid={`launch-guide-${l.id}`}
                                                        title="Abre este artigo no Guião de unidades (depois pode voltar à fatura).">Guião</Link>
                                                    <Button size="sm" color="link" className="py-0" disabled={refreshingArticle !== null} data-testid={`launch-refresh-${l.id}`}
                                                        title="Lê o artigo no PingWin agora (as unidades da cópia podem estar desatualizadas)." onClick={() => refreshFromPingwin(l.article!.pingwin_id)}>
                                                        {refreshingArticle === l.article!.pingwin_id ? <Spinner size="sm" /> : "Atualizar do PingWin"}
                                                    </Button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </>
            )}

            {/* Confirmações */}
            <Modal isOpen={confirm === "launch" || confirm === "launch_closed"} toggle={() => !busy && setConfirm(null)} centered data-testid="launch-modal">
                <ModalHeader toggle={() => !busy && setConfirm(null)}>{confirm === "launch_closed" ? "Gravar no PingWin como fechada?" : "Gravar no PingWin como rascunho?"}</ModalHeader>
                <ModalBody>
                    <dl className="row mb-2 fs-13">
                        <dt className="col-5">Fornecedor</dt><dd className="col-7">{p.supplier?.name ?? "—"}</dd>
                        <dt className="col-5">Fatura do fornecedor</dt><dd className="col-7">{p.docreference.number}{p.docreference.truncated && <span className="text-warning"> (cortado a 25 caracteres)</span>} · {fmtDay(p.docreference.date)}</dd>
                        <dt className="col-5">Loja e série</dt><dd className="col-7">{p.defaults.store ?? "—"} · {p.defaults.serie ?? "—"}</dd>
                        <dt className="col-5">Linhas</dt><dd className="col-7">{p.lines.length}</dd>
                        <dt className="col-5">Total</dt><dd className="col-7 fw-semibold">{eur(p.estimate.target_cents)}</dd>
                        <dt className="col-5">Acerto previsto</dt><dd className="col-7">{adj === null ? "—" : eur(adj)} <span className="text-muted">(o PingWin confirma antes de gravar; acima de 0,05 € não grava)</span></dd>
                    </dl>
                    {confirm === "launch_closed"
                        ? <Alert color="warning" className="py-2 fs-13 mb-2" data-testid="close-warning"><i className="ri-error-warning-line me-1" />{CLOSE_WARNING}</Alert>
                        : <Alert color="info" className="py-2 fs-13 mb-2"><i className="ri-draft-line me-1" />Fica em <strong>RASCUNHO (Aberto)</strong>: não mexe no stock nem na conta corrente até ser fechada.</Alert>}
                    {onlyCheck && confirm === "launch" && (
                        <div className="form-check">
                            <Input type="checkbox" className="form-check-input" id="accept-diff" checked={acceptDiff} onChange={(e) => setAcceptDiff(e.target.checked)} />
                            <Label className="form-check-label fs-13" for="accept-diff">As linhas não conferem com o QR; aceito lançar assim (fica registado).</Label>
                        </div>
                    )}
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setConfirm(null)} disabled={busy}>Cancelar</Button>
                    {confirm === "launch_closed" ? (
                        <ReasonButton color="primary" reason={closeReason} disabled={busy} onClick={() => run(() => launchOcrInvoice(companyId, invoiceId, { close: true }), true)}>
                            {busy ? <><Spinner size="sm" className="me-1" />A pedir…</> : "Gravar como fechada"}
                        </ReasonButton>
                    ) : (
                        <ReasonButton color="primary" reason={onlyCheck && !acceptDiff ? "Aceite a diferença das linhas para lançar." : null} disabled={busy}
                            onClick={() => run(() => launchOcrInvoice(companyId, invoiceId, { accept_check_diff: onlyCheck && acceptDiff }))}>
                            {busy ? <><Spinner size="sm" className="me-1" />A pedir…</> : "Gravar como rascunho"}
                        </ReasonButton>
                    )}
                </ModalFooter>
            </Modal>
            <Modal isOpen={confirm === "close"} toggle={() => !busy && setConfirm(null)} centered data-testid="close-modal">
                <ModalHeader toggle={() => !busy && setConfirm(null)}>Gravar {p.draft?.document} como fechada?</ModalHeader>
                <ModalBody>
                    <Alert color="warning" className="py-2 mb-0" data-testid="close-warning"><i className="ri-error-warning-line me-1" />{CLOSE_WARNING}</Alert>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setConfirm(null)} disabled={busy}>Cancelar</Button>
                    <ReasonButton color="primary" reason={closeReason} disabled={busy} onClick={() => run(() => closeOcrLaunch(companyId, invoiceId))}>{busy ? <Spinner size="sm" /> : "Gravar como fechada"}</ReasonButton>
                </ModalFooter>
            </Modal>
            <Modal isOpen={confirm === "void"} toggle={() => !busy && setConfirm(null)} centered data-testid="void-modal">
                <ModalHeader toggle={() => !busy && setConfirm(null)}>Anular {p.draft?.document} no PingWin?</ModalHeader>
                <ModalBody>
                    O documento fica Anulado no PingWin{p.draft?.docstatus_id === "8002" ? " e o stock volta ao que era" : ""}. A fatura volta a "Falta lançar".
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setConfirm(null)} disabled={busy}>Cancelar</Button>
                    <Button color="danger" disabled={busy} onClick={() => run(() => voidOcrLaunch(companyId, invoiceId))}>{busy ? <Spinner size="sm" /> : "Anular"}</Button>
                </ModalFooter>
            </Modal>
        </PageCard>
    );
}

/** As ligações ao Guião de unidades, uma por artigo (cada uma abre o seu artigo e volta à fatura). */
function GuideLinks({ articles, back }: { articles: OcrGuideArticle[]; back: string }) {
    return (
        <span className="d-flex flex-wrap gap-2 mt-1" data-testid="guide-links">
            {articles.map((a) => (
                <Link key={a.pingwin_id} to={unitGuideLink(a.pingwin_id, back)}><i className="ri-ruler-line me-1" />Abrir {a.description} no Guião de unidades</Link>
            ))}
        </span>
    );
}

/** O que falta para "Gravar como fechada" (com a ligação ao Guião de unidades de cada artigo, se forem as unidades). */
function CloseBlockers({ blockers, unitsMissing, back }: { blockers: { key: string; label: string; message: string | null; articles?: OcrGuideArticle[] }[]; unitsMissing: boolean; back: string }) {
    const arts = blockers.find((b) => b.key === "unidades_confirmadas")?.articles ?? [];
    return (
        <div className="fs-13 mt-2" data-testid="close-blockers">
            <div className="fw-semibold mb-1">Para gravar como fechada, falta:</div>
            <ul className="mb-0 ps-3">
                {blockers.map((b) => <li key={b.key}>{b.message ?? b.label}</li>)}
            </ul>
            {unitsMissing && (arts.length > 0
                ? <GuideLinks articles={arts} back={back} />
                : <Link to={UNIT_GUIDE} className="d-inline-block mt-1"><i className="ri-ruler-line me-1" />Abrir o Guião de unidades</Link>)}
        </div>
    );
}
