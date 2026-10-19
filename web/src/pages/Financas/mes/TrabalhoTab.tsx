import { Button, Nav, NavItem, NavLink } from "reactstrap";
import { FinanceMonth } from "common/models/financeMonth.model";
import DocumentosTab from "./DocumentosTab";
import TesourariaTab from "./TesourariaTab";
import HabituaisSection from "./HabituaisSection";
import { eur } from "./format";

/**
 * XPLENDOR — Finanças › Mês › Trabalho do mês (a terceira camada, só com Finanças › Editar): o que falta
 * fazer, em contadores que abrem a lista já filtrada, e o sítio de cada tarefa: os documentos (classificar,
 * registar pagamentos, fechar no PingWin), os pagamentos (a tesouraria e o Regularizar), o envio à
 * contabilidade, os habituais em falta e o fecho do mês.
 */
export type WorkSection = "documentos" | "pagamentos" | "contabilidade" | "habituais" | "fecho";
export const WORK_SECTIONS: { id: WorkSection; label: string; icon: string }[] = [
    { id: "documentos", label: "Documentos", icon: "ri-file-list-3-line" },
    { id: "pagamentos", label: "Pagamentos", icon: "ri-bank-line" },
    { id: "contabilidade", label: "Envio à contabilidade", icon: "ri-mail-send-line" },
    { id: "habituais", label: "Habituais em falta", icon: "ri-time-line" },
    { id: "fecho", label: "Fecho do mês", icon: "ri-file-pdf-2-line" },
];

/** As tarefas do mês: quantas, quanto e onde se tratam. */
export function workCounters(data: FinanceMonth) {
    const rows = data.documents.rows;
    const sum = (list: typeof rows, f: (r: (typeof rows)[number]) => number) => list.reduce((a, r) => a + f(r), 0);
    const toPay = rows.filter((r) => r.payment.state === "por_pagar");
    const overdue = rows.filter((r) => r.payment.state === "vencido");
    const toSend = rows.filter((r) => !r.accounting.sent);
    const unclassified = data.documents.unclassified;
    return [
        { key: "por_classificar", label: "Por classificar", count: unclassified.length, cents: sum(unclassified, (r) => r.unclassified_cents), sec: "documentos" as WorkSection, group: "por_classificar", state: "" },
        { key: "por_pagar", label: "Por pagar", count: toPay.length, cents: sum(toPay, (r) => r.payment.open_cents || r.total_cents), sec: "documentos" as WorkSection, group: "", state: "por_pagar" },
        { key: "vencido", label: "Vencidos", count: overdue.length, cents: sum(overdue, (r) => r.payment.open_cents || r.total_cents), sec: "documentos" as WorkSection, group: "", state: "vencido", danger: true },
        { key: "por_enviar", label: "Por enviar à contabilidade", count: toSend.length, cents: null, sec: "contabilidade" as WorkSection, group: "", state: "por_enviar" },
        { key: "aberto", label: "Em aberto no PingWin", count: data.documents.open.count, cents: data.documents.open.total_cents, sec: "documentos" as WorkSection, group: "", state: "aberto" },
        { key: "habituais", label: "Habituais em falta", count: data.provisional?.count ?? 0, cents: data.provisional?.cents ?? null, sec: "habituais" as WorkSection, group: "", state: "" },
    ];
}

export default function TrabalhoTab({ data, companyId, sec, group, state, onGo, onOpen, onChanged, onCloseMonth }: {
    data: FinanceMonth; companyId: number; sec: WorkSection; group: string; state: string;
    onGo: (sec: WorkSection, filter?: { group?: string; state?: string }) => void;
    onOpen: (docheaderId: string) => void; onChanged: () => void; onCloseMonth: () => void;
}) {
    const counters = workCounters(data);
    const pending = (k: string) => counters.find((c) => c.key === k)?.count ?? 0;

    return (
        <div data-testid="finance-work">
            <ul className="list-unstyled row g-2 mb-3" data-testid="work-counters">
                {counters.map((c) => (
                    <li key={c.key} className="col-12 col-sm-6 col-xl-4">
                        <button type="button" className="btn btn-light w-100 d-flex align-items-center gap-2 text-start py-2" data-testid={`work-${c.key}`}
                            onClick={() => onGo(c.sec, { group: c.group, state: c.state })}>
                            <i className={`fs-18 ${c.count === 0 ? "ri-checkbox-circle-line text-success" : c.danger ? "ri-error-warning-line text-danger" : "ri-checkbox-blank-circle-line text-warning"}`} aria-hidden />
                            <span className="flex-grow-1">
                                <span className="fw-medium">{c.label}</span>
                                {c.cents !== null && c.count > 0 && <span className="d-block fs-12 text-muted">{eur(c.cents)}</span>}
                            </span>
                            <span className={`badge fs-12 ${c.count === 0 ? "bg-success-subtle text-success" : c.danger ? "bg-danger-subtle text-danger" : "bg-warning-subtle text-warning"}`}>{c.count}</span>
                        </button>
                    </li>
                ))}
            </ul>

            <Nav pills className="mb-3 flex-column flex-sm-row gap-1" data-testid="work-sections">
                {WORK_SECTIONS.map((s) => (
                    <NavItem key={s.id}>
                        <NavLink href="#" active={sec === s.id} onClick={(e) => { e.preventDefault(); onGo(s.id); }} data-testid={`work-sec-${s.id}`}>
                            <i className={`${s.icon} me-1 align-bottom`} />{s.label}
                        </NavLink>
                    </NavItem>
                ))}
            </Nav>

            {sec === "documentos" && <DocumentosTab key={`d-${group}-${state}`} companyId={companyId} data={data} onOpen={onOpen} onChanged={onChanged} initialGroup={group} initialState={state} view="documentos" />}
            {sec === "pagamentos" && <TesourariaTab companyId={companyId} data={data} onOpen={onOpen} onChanged={onChanged} />}
            {sec === "contabilidade" && <DocumentosTab key={`c-${state}`} companyId={companyId} data={data} onOpen={onOpen} onChanged={onChanged} initialState={state || "por_enviar"} view="contabilidade" />}
            {sec === "habituais" && <HabituaisSection data={data} companyId={companyId} editable onChanged={onChanged} />}
            {sec === "fecho" && (
                <div data-testid="work-close">
                    <p className="fs-13 mb-2">O fecho do mês é a página para imprimir ou guardar em PDF, com o Resumo primeiro e depois o resultado por grupo e família. Antes de o gerar:</p>
                    <ul className="fs-13 mb-3 ps-3">
                        <li>Por classificar: {pending("por_classificar")}</li>
                        <li>Habituais em falta: {pending("habituais")}{pending("habituais") > 0 && " (o fecho sai como provisório)"}</li>
                        <li>Por enviar à contabilidade: {pending("por_enviar")}</li>
                    </ul>
                    <Button color="primary" onClick={onCloseMonth} data-testid="work-close-generate"><i className="ri-file-pdf-2-line me-1" />Gerar fecho do mês</Button>
                </div>
            )}
        </div>
    );
}
