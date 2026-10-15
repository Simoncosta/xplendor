import { Fragment, useEffect, useState } from "react";
import { Button, Spinner } from "reactstrap";
import Skeleton from "Components/Common/Skeleton";
import { getAuditFindings, getAuditGroups } from "helpers/laravel_helper";
import { AuditFinding, AuditGroup } from "common/models/audit.model";
import { AuditStateBadge, Cents, SeverityBadge } from "./auditFormat";

/**
 * XPLENDOR — Auditoria: "Agrupar por regra". Uma linha por regra (quantos achados, o dinheiro em
 * jogo, o a confirmar, a relevância e a urgência mais alta); cada uma expande-se nos primeiros achados,
 * para que regras com muitos achados (ex.: 221 "vendido sem ficha") não encham a lista.
 */
type Filters = { area?: string; rule?: string; severity?: string; state?: string; entity_type?: string; entity_id?: string; search?: string };
const FIRST = 10;

export default function AuditGroupsView({ companyId, filters, hidden, reloadKey, onOpenFinding, onShowRule }: {
    companyId: number; filters: Filters; hidden: boolean; reloadKey: number;
    onOpenFinding: (id: number) => void; onShowRule: (rule: string) => void;
}) {
    const [groups, setGroups] = useState<AuditGroup[] | null>(null);
    const [open, setOpen] = useState<Record<string, AuditFinding[] | "loading">>({});
    const key = JSON.stringify(filters);

    useEffect(() => {
        let alive = true;
        setGroups(null);
        setOpen({});
        getAuditGroups(companyId, filters).then((res: any) => { if (alive) setGroups(res?.data?.groups ?? []); }).catch(() => { if (alive) setGroups([]); });
        return () => { alive = false; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [companyId, key, reloadKey]);

    const toggle = async (g: AuditGroup) => {
        if (open[g.rule]) { setOpen((o) => { const n = { ...o }; delete n[g.rule]; return n; }); return; }
        setOpen((o) => ({ ...o, [g.rule]: "loading" }));
        try {
            const res: any = await getAuditFindings(companyId, { ...filters, rule: g.rule, perPage: FIRST });
            setOpen((o) => ({ ...o, [g.rule]: res?.data?.findings?.data ?? [] }));
        } catch {
            setOpen((o) => ({ ...o, [g.rule]: [] }));
        }
    };

    if (!groups) return <div className="p-3"><Skeleton variant="table" /></div>;
    if (!groups.length) return <div className="xp-dt-empty p-4 text-center text-muted">Nenhum achado para estes filtros.</div>;

    return (
        <div className="table-responsive" data-testid="audit-groups">
            <table className="table align-middle mb-0">
                <thead className="table-light">
                    <tr>
                        <th style={{ width: 32 }}><span className="visually-hidden">Expandir</span></th>
                        <th>Regra</th>
                        <th>Área</th>
                        <th className="text-end">Achados</th>
                        <th className="text-end">Em jogo</th>
                        <th className="text-end">A confirmar</th>
                        <th className="text-end">Relevância</th>
                        <th>Urgência</th>
                    </tr>
                </thead>
                <tbody>
                    {groups.map((g, i) => {
                        const rows = open[g.rule];
                        return (
                            <Fragment key={g.rule}>
                                <tr role="button" onClick={() => toggle(g)} aria-expanded={!!rows} data-rule={g.rule} style={{ cursor: "pointer" }}>
                                    <td><i className={`ri-arrow-right-s-line fs-18 text-muted d-inline-block`} style={{ transition: "transform .15s", transform: rows ? "rotate(90deg)" : "none" }} aria-hidden /></td>
                                    <td style={{ minWidth: 240 }}>
                                        <div className="fw-medium">{g.rule_title}</div>
                                        {g.rule_description && <div className="text-muted fs-12">{g.rule_description}</div>}
                                    </td>
                                    <td><span className="badge bg-light text-body">{g.area_label}</span></td>
                                    <td className="text-end fw-semibold">{g.count}</td>
                                    <td className="text-end text-nowrap">{g.impact_cents || hidden ? <Cents value={g.impact_cents} hidden={hidden} seed={i} /> : <span className="text-muted">—</span>}</td>
                                    <td className="text-end text-nowrap text-muted">{g.pending_cents || hidden ? <Cents value={g.pending_cents} hidden={hidden} seed={i + 1} /> : "—"}</td>
                                    <td className="text-end text-nowrap text-muted">{g.weight_cents || hidden ? <Cents value={g.weight_cents} hidden={hidden} seed={i + 2} /> : "—"}</td>
                                    <td><SeverityBadge severity={g.severity} /></td>
                                </tr>
                                {rows && (
                                    <tr className="bg-light-subtle">
                                        <td />
                                        <td colSpan={7} className="py-2">
                                            {rows === "loading" ? <Spinner size="sm" /> : (
                                                <>
                                                    <ul className="list-unstyled mb-2">
                                                        {rows.map((f) => (
                                                            <li key={f.id} className="d-flex flex-wrap align-items-center gap-2 py-1 border-bottom border-light-subtle">
                                                                <button type="button" className="btn btn-link p-0 text-start" onClick={() => onOpenFinding(f.id)}>{f.title}</button>
                                                                <span className="text-muted fs-12">{f.entity_label}</span>
                                                                <span className="ms-auto fs-13 text-nowrap">
                                                                    {f.impact_cents !== null ? <Cents value={f.impact_cents} hidden={hidden} seed={f.id} />
                                                                        : f.impact_pending_cents !== null ? <span className="text-muted">a confirmar <Cents value={f.impact_pending_cents} hidden={hidden} seed={f.id} /></span> : null}
                                                                </span>
                                                                <AuditStateBadge state={f.state} />
                                                            </li>
                                                        ))}
                                                    </ul>
                                                    {g.count > rows.length && (
                                                        <Button type="button" size="sm" color="link" className="p-0" onClick={() => onShowRule(g.rule)}>
                                                            Ver os {g.count} achados desta regra
                                                        </Button>
                                                    )}
                                                </>
                                            )}
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
