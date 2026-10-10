import { useCallback, useEffect, useState } from "react";
import { Card, CardBody, CardHeader, Col, Spinner } from "reactstrap";
import XSelect from "Components/Common/Select";
import { getPingwinHeatmap } from "helpers/laravel_helper";
import { useIsMobile } from "../../hooks/useIsMobile";
import { FINANCIAL_NOTE, FinancialNote } from "Components/Common/HiddenMoney";

/**
 * XPLENDOR — F2 do marketing da restauração: mapa de calor da semana (dia × hora), por loja.
 * Vendas (média sem IVA por hora) ou pessoas com reserva (média por hora de chegada), nas
 * últimas 12 semanas a partir do início efetivo da loja. Só aparece com o interruptor da
 * empresa ligado. Cores pelo tema (claro e escuro); no telemóvel, as horas ficam em linhas.
 * Na Bússola ("Dias para encher", Por hora) vem embutido, com a janela das jogadas: 8
 * semanas, sem os dias especiais, e a loja escolhida pela página. Aí, quem não vê as Finanças
 * recebe as vendas em intensidade (0 a 100, sem euros): o mapa mostra só as cores.
 */

type Matrix = { cells: Record<string, Record<string, number>>; days: Record<string, number>; max: number; unit?: "cents" | "people" | "relative" };
type Heatmap = {
    enabled: boolean;
    locations: { id: number; name: string }[];
    location_id: number | null;
    weeks: number;
    special_excluded?: number;
    from?: string;
    to?: string;
    hours: number[];
    sales: Matrix | null;
    guests: Matrix | null;
};

const WEEKDAYS: [number, string, string][] = [[1, "Seg", "Segunda"], [2, "Ter", "Terça"], [3, "Qua", "Quarta"], [4, "Qui", "Quinta"], [5, "Sex", "Sexta"], [6, "Sáb", "Sábado"], [7, "Dom", "Domingo"]];
const fmtDate = (d?: string) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit" }) : "");
const eurShort = (cents: number) => (cents / 100).toLocaleString("pt-PT", { maximumFractionDigits: 0 }) + " €";

type Props = {
    companyId: number;
    /** Semanas da janela (por omissão, 12). */
    weeks?: number;
    /** Sem os feriados e as datas especiais (a janela das jogadas da Bússola). */
    excludeSpecial?: boolean;
    /** Embutido noutro cartão: sem moldura, com a loja escolhida por fora. */
    embedded?: boolean;
    locationId?: number | null;
    /** Dentro da Bússola: segue a regra das Finanças (sem euros para quem não as vê). */
    forBussola?: boolean;
};

export default function HeatmapCard({ companyId, weeks, excludeSpecial, embedded, locationId: fixedLocation, forBussola }: Props) {
    const isMobile = useIsMobile();
    const [data, setData] = useState<Heatmap | null>(null);
    const [ownLocation, setLocationId] = useState<number | null>(null);
    const locationId = embedded ? (fixedLocation ?? null) : ownLocation;
    const [metric, setMetric] = useState<"sales" | "guests">("sales");
    const [loading, setLoading] = useState(false);

    const load = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getPingwinHeatmap(companyId, locationId, weeks, excludeSpecial, forBussola);
            setData(res?.data ?? null);
            if (locationId === null && res?.data?.location_id) setLocationId(res.data.location_id);
        } catch {
            setData(null);
        } finally {
            setLoading(false);
        }
    }, [companyId, locationId, weeks, excludeSpecial, forBussola]);

    useEffect(() => { load(); }, [load]);

    if (!data || !data.enabled) return null;

    const m = metric === "sales" ? data.sales : data.guests;
    const value = (wd: number, h: number) => m?.cells?.[wd]?.[h] ?? 0;
    const relative = metric === "sales" && m?.unit === "relative"; // sem Finanças: só as cores, sem euros
    const label = (v: number) => (relative ? "" : metric === "sales" ? eurShort(v) : v.toLocaleString("pt-PT", { maximumFractionDigits: 1 }));
    const alpha = (v: number) => (m && m.max > 0 ? Math.max(0.06, v / m.max) : 0);
    const cell = (wd: number, h: number, key: string) => {
        const v = value(wd, h);
        const a = alpha(v);
        return (
            <td key={key} title={`${WEEKDAYS[wd - 1][2]}, ${h}h: ${relative ? FINANCIAL_NOTE : label(v)}`} className="text-center p-0"
                style={{ background: v > 0 ? `rgba(var(--vz-primary-rgb), ${a.toFixed(2)})` : "transparent", color: a > 0.55 ? "#fff" : "var(--vz-body-color)", fontSize: 11, height: 30, minWidth: isMobile ? 0 : 44 }}>
                {v > 0 ? label(v) : ""}
            </td>
        );
    };
    const noData = !m || m.max === 0;
    const period = `${metric === "sales" ? "Média das vendas por hora (sem IVA)" : "Média das pessoas com reserva, pela hora de chegada"}`
        + (data.from ? `, de ${fmtDate(data.from)} a ${fmtDate(data.to)} (últimas ${data.weeks} semanas${excludeSpecial ? ", sem os dias especiais" : ""})` : "") + ".";
    const metricToggle = (
        <div className="xp-seg" role="radiogroup" aria-label="O que mostrar">
            <button type="button" role="radio" aria-checked={metric === "sales"} className={metric === "sales" ? "on" : ""} onClick={() => setMetric("sales")}>Vendas</button>
            <button type="button" role="radio" aria-checked={metric === "guests"} className={metric === "guests" ? "on" : ""} onClick={() => setMetric("guests")}>Pessoas</button>
        </div>
    );
    const body = (
        <>
            {noData ? (
                <p className="text-muted mb-0">
                    {metric === "sales" ? "Ainda não há vendas por hora lidas para esta loja." : "Ainda não há reservas por hora lidas para esta loja."}
                </p>
            ) : isMobile ? (
                <table className="w-100" style={{ tableLayout: "fixed", borderCollapse: "separate", borderSpacing: 2 }}>
                    <thead>
                        <tr>
                            <th className="text-muted fs-11" style={{ width: 34 }} />
                            {WEEKDAYS.map(([wd, short]) => <th key={wd} className="text-center text-muted fs-11 fw-medium">{short}</th>)}
                        </tr>
                    </thead>
                    <tbody>
                        {data.hours.map((h) => (
                            <tr key={h}>
                                <th className="text-muted fs-11 fw-medium">{h}h</th>
                                {WEEKDAYS.map(([wd]) => cell(wd, h, `${wd}-${h}`))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : (
                <div className="table-responsive">
                    <table style={{ borderCollapse: "separate", borderSpacing: 2 }}>
                        <thead>
                            <tr>
                                <th style={{ width: 80 }} />
                                {data.hours.map((h) => <th key={h} className="text-center text-muted fs-11 fw-medium">{h}h</th>)}
                            </tr>
                        </thead>
                        <tbody>
                            {WEEKDAYS.map(([wd, , long]) => (
                                <tr key={wd}>
                                    <th className="text-muted fs-12 fw-medium pe-2 text-nowrap">{long}</th>
                                    {data.hours.map((h) => cell(wd, h, `${wd}-${h}`))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {!noData && relative && <FinancialNote className="mt-2" />}
            {!noData && m && (
                <p className="text-muted fs-12 mt-2 mb-0">
                    Dias com dados: {WEEKDAYS.map(([wd, short]) => `${short} ${m.days[wd] ?? 0}`).join(", ")}.
                </p>
            )}
        </>
    );

    if (embedded) {
        return (
            <div data-testid="heatmap-embedded">
                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <p className="text-muted fs-13 mb-0">{period} {loading && <Spinner size="sm" className="ms-1" />}</p>
                    {metricToggle}
                </div>
                {body}
            </div>
        );
    }

    return (
        <Col xs={12}>
            <Card className="mb-0">
                <CardHeader className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h5 className="card-title mb-1">Mapa da semana {loading && <Spinner size="sm" className="ms-1" />}</h5>
                        <p className="text-muted fs-13 mb-0">{period}</p>
                    </div>
                    <div className="d-flex flex-wrap align-items-center gap-2">
                        {data.locations.length > 1 && (
                            <XSelect small ariaLabel="Loja" width={200} value={locationId}
                                options={data.locations.map((l) => ({ value: l.id, label: l.name }))} onChange={(v) => setLocationId(v)} />
                        )}
                        {metricToggle}
                    </div>
                </CardHeader>
                <CardBody>
                    {body}
                </CardBody>
            </Card>
        </Col>
    );
}
