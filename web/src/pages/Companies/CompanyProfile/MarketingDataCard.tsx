import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardBody, Col, Spinner } from "reactstrap";
import { getPingwinMarketingData, updateAnnualLocals } from "helpers/laravel_helper";
import { toast } from "react-toastify";
import ReasonButton from "Components/Common/ReasonButton";
import { MarketingData, MarketingDataLocation } from "common/models/pingwin.model";
import { useIsMobile } from "../../../hooks/useIsMobile";

/**
 * XPLENDOR — F1-3 do marketing da restauração: cartão "Dados para o marketing" nas
 * Integrações (documents/PINGWIN-F1-DESENHO.md §5). Por loja: início efetivo (a abertura
 * indicada manda; o início detetado aparece ao lado, com aviso quando diferem mais de 7
 * dias), dias lidos e estado do histórico. E ainda: conferência com o líquido diário,
 * cobertura do catálogo e famílias por confirmar. Só leitura.
 */

const fmtDate = (d: string | null, monthOnly = false) => {
    if (!d) return "";
    const date = new Date(`${d.slice(0, 10)}T00:00:00`);
    return monthOnly
        ? date.toLocaleDateString("pt-PT", { month: "long", year: "numeric" })
        : date.toLocaleDateString("pt-PT", { day: "2-digit", month: "short", year: "numeric" });
};
/** Singular ou plural pelo número ("1 dia marcado", "2 dias marcados"). */
const pl = (n: number, one: string, many: string) => (n === 1 ? one : many);
const fmtPct = (v: number | null) => (v === null ? "" : `${v.toLocaleString("pt-PT", { maximumFractionDigits: 1 })}%`);

function StartCell({ l }: { l: MarketingDataLocation }) {
    if (!l.effective_start) {
        return <span className="text-muted">{l.start_checked ? "Sem vendas encontradas" : "Por detetar"}</span>;
    }
    return (
        <div>
            <div className="text-body">{fmtDate(l.effective_start)}{l.opened_on && <span className="text-muted fs-12"> (abertura indicada)</span>}</div>
            {l.opened_on && l.detected_start && (
                <div className="text-muted fs-12">Detetado: {l.detected_is_month ? fmtDate(l.detected_start, true) : fmtDate(l.detected_start)}</div>
            )}
            {!l.opened_on && l.detected_is_month && <div className="text-muted fs-12">Mês detetado; o dia exato fica no fim do histórico.</div>}
            {l.start_warning && (
                <div className="text-warning fs-12"><i className="ri-error-warning-line me-1" />Difere {l.start_difference_days} {pl(l.start_difference_days ?? 0, "dia", "dias")} da abertura indicada.</div>
            )}
        </div>
    );
}

function HistoryCell({ l }: { l: MarketingDataLocation }) {
    if (l.history_complete) return <span className="badge bg-success-subtle text-success">Completo</span>;
    if (l.days_read > 0) return <span className="badge bg-warning-subtle text-warning">A importar</span>;
    return <span className="badge bg-light text-muted">Por começar</span>;
}

/**
 * Só o root: postos de venda do relatório anual (início de cada loja), escritos à mão a
 * partir de uma captura. Vazio = o relatório soma todos os postos de venda.
 */
function LocalsField({ companyId, initial }: { companyId: number; initial: string }) {
    const [value, setValue] = useState(initial);
    const [saved, setSaved] = useState(initial);
    const [saving, setSaving] = useState(false);
    const save = async () => {
        setSaving(true);
        try {
            const res: any = await updateAnnualLocals(companyId, value.trim());
            setSaved(res?.data?.annual_locals ?? value.trim());
            setValue(res?.data?.annual_locals ?? value.trim());
            toast.success(res?.message ?? "Postos de venda guardados.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar os postos de venda.");
        } finally {
            setSaving(false);
        }
    };
    return (
        <div className="mb-3 p-3 rounded" style={{ background: "var(--vz-tertiary-bg)", border: "1px dashed var(--vz-border-color)" }}>
            <label htmlFor="pingwin-annual-locals" className="form-label fs-13 fw-semibold mb-1">Postos de venda do relatório anual (só root)</label>
            <p className="text-muted fs-12 mb-2">IDs separados por vírgulas, copiados de uma captura do back-office. Vazio: o relatório soma todos os postos de venda.</p>
            <div className="d-flex flex-wrap gap-2">
                <input id="pingwin-annual-locals" className="form-control form-control-sm" style={{ maxWidth: 520 }} value={value}
                    onChange={(e) => setValue(e.target.value)} placeholder="Todos os postos de venda" inputMode="numeric" />
                <ReasonButton size="sm" color="outline-primary" onClick={save} disabled={saving}
                    reason={saving ? null : value.trim() === saved ? "Sem alterações para guardar." : null}>
                    {saving ? <Spinner size="sm" /> : "Guardar"}
                </ReasonButton>
            </div>
        </div>
    );
}

export default function MarketingDataCard({ companyId }: { companyId: number }) {
    const isMobile = useIsMobile();
    const [data, setData] = useState<MarketingData | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        setFailed(false);
        try {
            const res: any = await getPingwinMarketingData(companyId);
            setData(res?.data ?? null);
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    }, [companyId]);

    useEffect(() => { load(); }, [load]);

    const tile = (title: string, value: React.ReactNode, note: React.ReactNode) => (
        <Col xs={12} md={6} xl={3}>
            <div className="p-3 rounded h-100" style={{ background: "var(--vz-tertiary-bg)", border: "1px solid var(--vz-border-color)" }}>
                <div className="text-muted fs-12 mb-1">{title}</div>
                <div className="fs-16 fw-semibold text-body mb-1">{value}</div>
                <div className="text-muted fs-12">{note}</div>
            </div>
        </Col>
    );

    return (
        <Col xs={12}>
            <Card className="mb-0">
                <CardBody>
                    <div className="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
                        <div className="d-flex align-items-center gap-3">
                            <div className="rounded d-flex align-items-center justify-content-center flex-shrink-0" style={{ width: 44, height: 44, background: "#0AB39C" }}>
                                <i className="ri-bar-chart-2-line text-white fs-20" />
                            </div>
                            <div>
                                <h6 className="fw-semibold mb-0">Dados para o marketing {loading && <Spinner size="sm" className="ms-1" />}</h6>
                                <p className="text-muted fs-12 mb-0">Vendas por artigo do PingWin</p>
                            </div>
                        </div>
                        {data && (
                            <span className={`badge fs-11 ${data.enabled ? "bg-success-subtle text-success" : "bg-light text-muted"}`}>
                                {data.enabled ? "Leitura ligada" : "Leitura desligada"}
                            </span>
                        )}
                    </div>

                    {failed && <p className="text-muted fs-13 mb-0">Não foi possível carregar os dados para o marketing.</p>}

                    {data && (
                        <>
                            {!data.enabled && (
                                <p className="text-muted fs-13 mb-3">
                                    A leitura das vendas por artigo está desligada nesta empresa. Liga-se depois de validada; até lá, os valores abaixo ficam como estão.
                                </p>
                            )}

                            <div className="row g-3 mb-3">
                                {tile(
                                    "Conferência com o líquido diário (90 dias)",
                                    data.days.checked > 0 ? `${data.days.ok} de ${data.days.checked} ${pl(data.days.checked, "dia", "dias")} ${pl(data.days.ok, "bate", "batem")}` : "Sem dias lidos",
                                    data.days.marked > 0 ? `${data.days.marked} ${pl(data.days.marked, "dia marcado", "dias marcados")} para voltar a ler` : "Sem dias marcados",
                                )}
                                {tile(
                                    "Catálogo",
                                    data.catalog.coverage_pct === null ? "Sem vendas por artigo" : `${fmtPct(data.catalog.coverage_pct)} dos artigos vendidos`,
                                    data.catalog.sold > 0 ? `${data.catalog.missing} de ${data.catalog.sold} ${pl(data.catalog.sold, "artigo vendido", "artigos vendidos")} (90 dias) fora do catálogo` : "Conta os artigos vendidos nos últimos 90 dias",
                                )}
                                {tile(
                                    "Categorias das famílias",
                                    data.families.total === 0 ? "Sem famílias com vendas" : data.families.unconfirmed > 0 ? `${data.families.unconfirmed} de ${data.families.total} por confirmar` : "Todas confirmadas",
                                    <Link to="/restauracao/categorias">Ver as categorias</Link>,
                                )}
                                {data.reservations && tile(
                                    `Reservas por classificar (${data.reservations.days} dias)`,
                                    data.reservations.unclassified === 0
                                        ? "Nenhuma"
                                        : <span className="text-warning">{data.reservations.unclassified} {pl(data.reservations.unclassified, "reserva", "reservas")}</span>,
                                    data.reservations.unclassified === 0
                                        ? "Todos os códigos de estado do CoverManager estão no mapa"
                                        : <>
                                            {Object.keys(data.reservations.codes).length > 0 && <>Códigos fora do mapa: {Object.entries(data.reservations.codes).map(([c, n]) => `"${c}" (${n})`).join(", ")}. </>}
                                            Não contam nas válidas nem nas anuladas.
                                        </>,
                                )}
                            </div>

                            {data.can_edit_locals && <LocalsField companyId={companyId} initial={data.annual_locals ?? ""} />}

                            <h6 className="fs-13 fw-semibold mb-2">Por loja</h6>
                            {data.locations.length === 0 ? (
                                <p className="text-muted fs-13 mb-0">Sem lojas ativas.</p>
                            ) : isMobile ? (
                                <div className="d-flex flex-column gap-2">
                                    {data.locations.map((l) => (
                                        <div key={l.location_id} className="border rounded p-2" style={{ borderColor: "var(--vz-border-color)" }}>
                                            <div className="d-flex justify-content-between align-items-center mb-1">
                                                <span className="fw-semibold text-body">{l.name}</span>
                                                <HistoryCell l={l} />
                                            </div>
                                            <div className="fs-13"><StartCell l={l} /></div>
                                            <div className="text-muted fs-12 mt-1">
                                                {l.days_read} {pl(l.days_read, "dia lido", "dias lidos")}{l.oldest_day_read ? `, desde ${fmtDate(l.oldest_day_read)}` : ""}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="table-responsive">
                                    <table className="table table-sm align-middle mb-0">
                                        <thead className="table-light">
                                            <tr><th>Loja</th><th>Início</th><th className="text-end">Dias lidos</th><th>Histórico</th></tr>
                                        </thead>
                                        <tbody>
                                            {data.locations.map((l) => (
                                                <tr key={l.location_id}>
                                                    <td className="fw-semibold text-body">{l.name}</td>
                                                    <td><StartCell l={l} /></td>
                                                    <td className="text-end">
                                                        {l.days_read}
                                                        {l.oldest_day_read && <div className="text-muted fs-12">desde {fmtDate(l.oldest_day_read)}</div>}
                                                    </td>
                                                    <td><HistoryCell l={l} /></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </>
                    )}
                </CardBody>
            </Card>
        </Col>
    );
}
