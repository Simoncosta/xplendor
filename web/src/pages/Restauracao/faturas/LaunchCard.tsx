import { useCallback, useEffect, useState } from "react";
import { Alert, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import PageCard from "Components/Common/PageCard";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import ActionsMenu from "Components/Common/ActionsMenu";
import { closeOcrLaunch, getOcrLaunch, launchOcrInvoice, setOcrLineLaunchUnit, voidOcrLaunch } from "helpers/laravel_helper";
import { OcrLaunchPreview } from "common/models/ocr.model";

/**
 * XPLENDOR — FB-1: "Lançar no PingWin". Cria a Fatura de fornecedor em RASCUNHO (Aberto: não mexe
 * no stock nem na conta corrente); depois "Fechar no PingWin" ou "Anular rascunho". Cada ação é uma
 * escrita assíncrona confirmada por releitura; o cartão acompanha-a (polling). Se o botão estiver
 * desativado, a lista diz o que falta.
 */

type Props = { companyId: number; invoiceId: number; onChanged: () => void };

const eur = (cents?: number | null) => ((cents ?? 0) / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR" });
const fmtDay = (d?: string | null) => (d ? d.split("-").reverse().join("/") : "—");
const ACTION_LABEL = { launch: "lançar", close: "fechar", void: "anular" } as const;

export default function LaunchCard({ companyId, invoiceId, onChanged }: Props) {
    const [p, setP] = useState<OcrLaunchPreview | null>(null);
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState<"launch" | "close" | "void" | null>(null);
    const [acceptDiff, setAcceptDiff] = useState(false);
    const [unitDraft, setUnitDraft] = useState<Record<number, { unit_id: string; quantity: string }>>({});

    const load = useCallback(async () => {
        try {
            const r: any = await getOcrLaunch(companyId, invoiceId);
            setP(r?.data ?? null);
        } catch { /* fica o que está */ }
    }, [companyId, invoiceId]);

    useEffect(() => { load(); }, [load]);

    // Polling enquanto há uma escrita em curso; no fim avisa a página (F3 e lista).
    const pending = p?.writes.find((w) => w.status === "pendente");
    useEffect(() => {
        if (!pending) return;
        const t = setInterval(async () => {
            const r: any = await getOcrLaunch(companyId, invoiceId).catch(() => null);
            const np: OcrLaunchPreview | null = r?.data ?? null;
            if (!np) return;
            setP(np);
            const w = np.writes.find((x) => x.id === pending.id);
            if (w && w.status !== "pendente") {
                if (w.status === "ok") toast.success(w.action === "launch" ? `Lançada em rascunho: ${w.document}.` : w.action === "close" ? `Fechada no PingWin: ${w.document}.` : `Rascunho anulado: ${w.document}.`);
                else toast.error(`Não foi possível ${ACTION_LABEL[w.action]}: ${w.error ?? "erro"}`);
                onChanged();
            }
        }, 3000);
        return () => clearInterval(t);
    }, [pending, companyId, invoiceId, onChanged]);

    if (!p) return null;

    const failed = p.guards.filter((g) => !g.ok);
    const onlyCheck = failed.length === 1 && failed[0].key === "conferencia";
    const draftActive = p.draft && p.draft.docstatus_id !== "8003";
    const last = p.writes[0];
    const outOfScope = failed.some((g) => g.key === "tipo");
    const launchReason = outOfScope ? "Por agora, lançar à mão no PingWin."
        : p.can_launch || onlyCheck ? null : failed.map((g) => g.message).join(" ");

    const run = async (fn: () => Promise<any>) => {
        setBusy(true);
        try {
            const r: any = await fn();
            if (r?.data) setP(r.data);
            setConfirm(null);
            setAcceptDiff(false);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível concluir.");
            if (e?.errors?.failed) load();
        } finally {
            setBusy(false);
        }
    };

    const saveUnit = async (lineId: number) => {
        const d = unitDraft[lineId];
        if (!d?.unit_id) return;
        await run(() => setOcrLineLaunchUnit(companyId, invoiceId, lineId, { unit_id: d.unit_id, quantity: d.quantity === "" ? null : Number(d.quantity) }));
    };

    // A escolha de unidades só interessa a uma fatura que se pode lançar (FT e ainda não lançada).
    const launchable = !failed.some((g) => g.key === "tipo" || g.key === "nao_lancada");
    const needsUnit = launchable ? p.lines.filter((l) => !l.unit_ok && l.article) : [];
    const adj = p.estimate.adjustment_cents;

    const actions = draftActive ? (
        <>
            {p.draft!.docstatus_id === "8001" && (
                <Button size="sm" color="primary" onClick={() => setConfirm("close")} disabled={busy || !!pending}><i className="ri-lock-line me-1" />Fechar no PingWin</Button>
            )}
            <ActionsMenu size="sm" label="Mais ações do documento" disabled={busy || !!pending} items={[
                { label: p.draft!.docstatus_id === "8001" ? "Anular rascunho" : "Anular no PingWin", icon: "ri-close-circle-line", danger: true, onClick: () => setConfirm("void") },
            ]} />
        </>
    ) : (
        <ReasonButton size="sm" color="primary" reason={pending ? "Há uma escrita em curso." : launchReason} disabled={busy} onClick={() => setConfirm("launch")}>
            <i className="ri-upload-cloud-2-line me-1" />Lançar no PingWin
        </ReasonButton>
    );

    return (
        <PageCard
            title="Lançar no PingWin"
            info="Cria a fatura de fornecedor no PingWin em RASCUNHO (Aberto): não mexe no stock nem na conta corrente até ser fechada."
            status={draftActive
                ? <span className={`badge ${p.draft!.docstatus_id === "8001" ? "bg-info-subtle text-info" : "bg-success-subtle text-success"}`}>{p.draft!.document} · {p.draft!.docstatus_label}</span>
                : p.can_launch ? <span className="badge bg-success-subtle text-success">Pronta para lançar</span> : <span className="text-muted">{failed.length} {failed.length === 1 ? "coisa por resolver" : "coisas por resolver"}</span>}
            actions={actions}
            flush={false}
            data-testid="launch-card"
        >
            {pending && (
                <div className="fs-13 mb-2" data-testid="launch-pending"><Spinner size="sm" className="me-1" />A {ACTION_LABEL[pending.action]} no PingWin… (confirmação por releitura)</div>
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
                        : <p className="mb-1"><i className="ri-check-double-line me-1 text-success" />Lançada e fechada no PingWin: <strong>{p.draft!.document}</strong>.</p>}
                    <p className="text-muted mb-0">Total {(p.draft!.total).toLocaleString("pt-PT", { style: "currency", currency: "EUR" })} · {p.draft!.store_name ?? "—"} · lançada a {fmtDay(p.draft!.doc_date)}</p>
                </div>
            ) : (
                <>
                    <ul className="list-unstyled mb-2 fs-13" data-testid="launch-guards">
                        {p.guards.map((g) => (
                            <li key={g.key} className="d-flex gap-2 mb-1">
                                <i className={g.ok ? "ri-checkbox-circle-line text-success" : "ri-close-circle-line text-danger"} aria-hidden />
                                <span>{g.label}{!g.ok && g.message && <span className="text-muted"> — {g.message}</span>}</span>
                            </li>
                        ))}
                    </ul>
                    {needsUnit.length > 0 && (
                        <div className="table-responsive">
                            <table className="table table-sm table-bordered align-middle mb-0 fs-13" data-testid="launch-units">
                                <caption className="visually-hidden">Linhas sem unidade</caption>
                                <thead className="table-light text-muted"><tr><th>Linha</th><th>Na fatura</th><th style={{ minWidth: 180 }}>Unidade do artigo</th><th style={{ width: 110 }}>Quantidade</th><th /></tr></thead>
                                <tbody>
                                    {needsUnit.map((l) => {
                                        const d = unitDraft[l.id] ?? { unit_id: "", quantity: l.quantity !== null ? String(l.quantity) : "" };
                                        return (
                                            <tr key={l.id}>
                                                <td>{l.article?.code} {l.article?.description}</td>
                                                <td>{l.quantity ?? "—"} {l.ocr_unit ?? "(sem unidade)"}</td>
                                                <td><XSelect small ariaLabel={`Unidade da linha ${l.position + 1}`} options={l.unit_options} value={d.unit_id} placeholder="Escolher…" searchable={false}
                                                    onChange={(v) => setUnitDraft((s) => ({ ...s, [l.id]: { ...d, unit_id: v } }))} /></td>
                                                <td><Input bsSize="sm" type="number" step="any" min={0} className="text-end" aria-label={`Quantidade da linha ${l.position + 1}`} value={d.quantity}
                                                    onChange={(e) => setUnitDraft((s) => ({ ...s, [l.id]: { ...d, quantity: e.target.value } }))} /></td>
                                                <td><ReasonButton size="sm" color="outline-primary" reason={!d.unit_id ? "Escolha a unidade." : null} disabled={busy} onClick={() => saveUnit(l.id)}>Guardar</ReasonButton></td>
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
            <Modal isOpen={confirm === "launch"} toggle={() => !busy && setConfirm(null)} centered data-testid="launch-modal">
                <ModalHeader toggle={() => !busy && setConfirm(null)}>Lançar no PingWin?</ModalHeader>
                <ModalBody>
                    <dl className="row mb-2 fs-13">
                        <dt className="col-5">Fornecedor</dt><dd className="col-7">{p.supplier?.name ?? "—"}</dd>
                        <dt className="col-5">Fatura do fornecedor</dt><dd className="col-7">{p.docreference.number}{p.docreference.truncated && <span className="text-warning"> (cortado a 25 caracteres)</span>} · {fmtDay(p.docreference.date)}</dd>
                        <dt className="col-5">Loja e série</dt><dd className="col-7">{p.defaults.store ?? "—"} · {p.defaults.serie ?? "—"}</dd>
                        <dt className="col-5">Linhas</dt><dd className="col-7">{p.lines.length}</dd>
                        <dt className="col-5">Total</dt><dd className="col-7 fw-semibold">{eur(p.estimate.target_cents)}</dd>
                        <dt className="col-5">Acerto previsto</dt><dd className="col-7">{adj === null ? "—" : eur(adj)} <span className="text-muted">(o PingWin confirma antes de gravar; acima de 0,05 € não grava)</span></dd>
                    </dl>
                    <Alert color="info" className="py-2 fs-13 mb-2"><i className="ri-draft-line me-1" />Fica em <strong>RASCUNHO (Aberto)</strong>: não mexe no stock nem na conta corrente até ser fechada.</Alert>
                    {onlyCheck && (
                        <div className="form-check">
                            <Input type="checkbox" className="form-check-input" id="accept-diff" checked={acceptDiff} onChange={(e) => setAcceptDiff(e.target.checked)} />
                            <Label className="form-check-label fs-13" for="accept-diff">As linhas não conferem com o QR; aceito lançar assim (fica registado).</Label>
                        </div>
                    )}
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setConfirm(null)} disabled={busy}>Cancelar</Button>
                    <ReasonButton color="primary" reason={onlyCheck && !acceptDiff ? "Aceite a diferença das linhas para lançar." : null} disabled={busy}
                        onClick={() => run(() => launchOcrInvoice(companyId, invoiceId, { accept_check_diff: onlyCheck && acceptDiff }))}>
                        {busy ? <><Spinner size="sm" className="me-1" />A pedir…</> : "Lançar em rascunho"}
                    </ReasonButton>
                </ModalFooter>
            </Modal>
            <Modal isOpen={confirm === "close"} toggle={() => !busy && setConfirm(null)} centered data-testid="close-modal">
                <ModalHeader toggle={() => !busy && setConfirm(null)}>Fechar {p.draft?.document} no PingWin?</ModalHeader>
                <ModalBody>
                    <Alert color="warning" className="py-2 mb-0"><i className="ri-error-warning-line me-1" /><strong>Mexe no stock e na conta corrente.</strong> O documento deixa de ser rascunho e conta como compra lançada.</Alert>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setConfirm(null)} disabled={busy}>Cancelar</Button>
                    <Button color="primary" disabled={busy} onClick={() => run(() => closeOcrLaunch(companyId, invoiceId))}>{busy ? <Spinner size="sm" /> : "Fechar no PingWin"}</Button>
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
