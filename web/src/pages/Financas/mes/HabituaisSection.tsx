import { useState } from "react";
import { Link } from "react-router-dom";
import { Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { excludeHabitual, restoreHabitual } from "helpers/laravel_helper";
import { FinanceMonth } from "common/models/financeMonth.model";
import { dmy, eur } from "./format";

/**
 * XPLENDOR — Finanças › Mês: os documentos habituais em falta (a renda, a folha, a luz) e os que alguém
 * tirou da lista neste mês. Com Finanças › Editar: "Não vem este mês" e "Não é habitual" (com
 * confirmação; o resultado estimado recalcula logo) e "Repor". Sem permissão, só a lista.
 */
type Missing = NonNullable<FinanceMonth["provisional"]>["missing"][number];

export default function HabituaisSection({ data, companyId, editable, onChanged }: {
    data: FinanceMonth; companyId?: number | null; editable: boolean; onChanged?: () => void;
}) {
    const [asking, setAsking] = useState<{ m: Missing; scope: "mes" | "sempre" } | null>(null);
    const [reason, setReason] = useState("");
    const [busy, setBusy] = useState(false);
    const missing = data.provisional?.missing ?? [];
    const excluded = data.habitual_excluded ?? [];
    const canEdit = editable && !!companyId;

    const confirm = async () => {
        if (!asking || !companyId) return;
        setBusy(true);
        try {
            const r: any = await excludeHabitual(companyId, { entity_pingwin_id: asking.m.entity_pingwin_id, scope: asking.scope, month: data.month,
                avg_cents: asking.m.avg_cents, reason: reason.trim() || null });
            toast.success(r?.message ?? "Recalculado.");
            setAsking(null);
            setReason("");
            onChanged?.();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar.");
        } finally {
            setBusy(false);
        }
    };
    const restore = async (id: number) => {
        if (!companyId) return;
        try {
            const r: any = await restoreHabitual(companyId, id);
            toast.success(r?.message ?? "Reposto.");
            onChanged?.();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível repor.");
        }
    };

    return (
        <div data-testid="habituais-section">
            {missing.length === 0
                ? <p className="fs-13 text-muted mb-2"><i className="ri-checkbox-circle-line text-success me-1" />Todos os documentos habituais de {data.month_label} já chegaram.</p>
                : (
                    <ul className="list-unstyled mb-2" data-testid="finance-missing">
                        {missing.map((m) => (
                            <li key={m.entity_pingwin_id} className="d-flex flex-wrap align-items-baseline gap-2 py-2 border-bottom fs-13" data-testid={`missing-${m.entity_pingwin_id}`}>
                                <span className="flex-grow-1">
                                    <span className="fw-medium">{m.supplier ?? "—"}</span>: cerca de {eur(m.avg_cents)}
                                    <div className="fs-12 text-muted">Veio em {m.months} dos 3 meses anteriores; o último foi {m.last_document ?? "—"} de {dmy(m.last_date)}.</div>
                                </span>
                                {canEdit && <span className="text-nowrap">
                                    <Button size="sm" color="outline-secondary" className="me-2" onClick={() => setAsking({ m, scope: "mes" })}>Não vem este mês</Button>
                                    <Button size="sm" color="outline-secondary" onClick={() => setAsking({ m, scope: "sempre" })}>Não é habitual</Button>
                                </span>}
                            </li>
                        ))}
                    </ul>
                )}
            {excluded.length > 0 && (
                <div className="fs-12 text-muted mb-2" data-testid="finance-excluded">
                    Fora dos habituais neste mês: {excluded.map((e, i) => (
                        <span key={e.id}>{i > 0 && " · "}{e.supplier} ({e.scope === "mes" ? "não vem este mês" : "não é habitual"}{e.by ? `, por ${e.by}` : ""})
                            {canEdit && <Button size="sm" color="link" className="p-0 ms-1 fs-12 align-baseline" onClick={() => restore(e.id)}>Repor</Button>}</span>
                    ))}
                </div>
            )}
            {canEdit && <Link to="/financas/configuracao/habituais" className="fs-12">Ver todos os que deixaram de ser habituais</Link>}
            <Modal isOpen={!!asking} toggle={() => !busy && setAsking(null)} centered data-testid="habitual-modal">
                <ModalHeader toggle={() => !busy && setAsking(null)}>{asking?.scope === "mes" ? "Não vem este mês?" : "Deixa de ser habitual?"}</ModalHeader>
                <ModalBody>
                    <p className="fs-13">{asking?.scope === "mes"
                        ? <><strong>{asking?.m.supplier}</strong> sai da lista só em {data.month_label}. No mês seguinte volta a contar, se faltar.</>
                        : <><strong>{asking?.m.supplier}</strong> deixa de entrar nos documentos habituais a partir de {data.month_label}. Pode repor em Finanças › Configuração.</>}
                        {" "}O resultado estimado deixa de contar com cerca de {eur(asking?.m.avg_cents ?? 0)}.</p>
                    <Label className="form-label mb-1" for="habitual-reason">Motivo (opcional)</Label>
                    <Input id="habitual-reason" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Por exemplo, o contrato terminou" />
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setAsking(null)} disabled={busy}>Cancelar</Button>
                    <Button color="primary" onClick={confirm} disabled={busy} data-testid="habitual-confirm">{busy ? <Spinner size="sm" /> : asking?.scope === "mes" ? "Não vem este mês" : "Não é habitual"}</Button>
                </ModalFooter>
            </Modal>
        </div>
    );
}
