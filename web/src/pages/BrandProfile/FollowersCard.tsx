import { useCallback, useEffect, useMemo, useState } from "react";
import ReactApexChart from "react-apexcharts";
import { Button, ButtonGroup, Card, CardBody, CardHeader, Col, Input, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import getChartColorsArray from "Components/Common/ChartsDynamicColor";
import { getFollowers, recordFollowers } from "helpers/laravel_helper";
import type { FollowerPlatform, FollowerSource, FollowersOverview } from "common/models/followers.model";

/**
 * Seguidores (Instagram e Página de Facebook): valor atual, registo manual de hoje e
 * crescimento. Os dias sem registo ficam como falhas (sem inventar valores) e os pontos
 * registados à mão aparecem marcados. A leitura automática chega com a ligação às redes;
 * até lá, o estado diz-o com honestidade.
 */

const PLATFORMS: { key: FollowerPlatform; label: string; icon: string; colorVar: string }[] = [
    { key: "instagram", label: "Instagram", icon: "ri-instagram-line", colorVar: "--vz-danger" },
    { key: "facebook", label: "Página de Facebook", icon: "ri-facebook-circle-line", colorVar: "--vz-primary" },
];

const WINDOWS = [30, 90, 365] as const;

const int = (v: number) => new Intl.NumberFormat("pt-PT").format(v);
const dmy = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" });

const SOURCE_LABEL: Record<FollowerSource, string> = {
    manual: "Registo manual",
    api: "Leitura automática",
    business_discovery: "Leitura automática (perfil público)",
};

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

export default function FollowersCard({ companyId }: { companyId: number }) {
    const [days, setDays] = useState<(typeof WINDOWS)[number]>(90);
    const [data, setData] = useState<FollowersOverview | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const [draft, setDraft] = useState<Record<FollowerPlatform, string>>({ instagram: "", facebook: "" });
    const [saving, setSaving] = useState<FollowerPlatform | null>(null);

    const load = useCallback(() => {
        if (!companyId) return;
        setLoading(true);
        setError(false);
        getFollowers(companyId, days)
            .then((r: any) => setData(r?.data ?? null))
            .catch(() => setError(true))
            .finally(() => setLoading(false));
    }, [companyId, days]);

    useEffect(() => { load(); }, [load]);

    const save = async (platform: FollowerPlatform) => {
        const value = Number(draft[platform]);
        if (draft[platform].trim() === "" || !Number.isInteger(value) || value < 0) {
            toast.error("Indique um número inteiro de seguidores.");
            return;
        }
        setSaving(platform);
        try {
            const r: any = await recordFollowers(companyId, { platform, followers_count: value });
            toast.success("Seguidores registados.");
            setDraft((d) => ({ ...d, [platform]: "" }));
            // A resposta traz sempre a janela de 90 dias; recarrega a janela escolhida.
            if (days === 90 && r?.data) setData(r.data); else load();
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível registar os seguidores."));
        } finally {
            setSaving(null);
        }
    };

    const chart = useMemo(() => {
        if (!data) return null;
        const colors = getChartColorsArray(JSON.stringify(PLATFORMS.map((p) => p.colorVar)));
        const series = PLATFORMS.map((p) => ({
            name: p.label,
            data: data.platforms[p.key].series.map((pt) => ({ x: new Date(`${pt.date}T00:00:00`).getTime(), y: pt.count })),
        }));
        // Pontos registados à mão: marcador quadrado na cor da rede (visível em claro e em escuro).
        const discrete = PLATFORMS.flatMap((p, seriesIndex) =>
            data.platforms[p.key].series
                .map((pt, dataPointIndex) => ({ pt, dataPointIndex }))
                .filter(({ pt }) => pt.count !== null && pt.source === "manual")
                .map(({ dataPointIndex }) => ({ seriesIndex, dataPointIndex, fillColor: colors[seriesIndex], strokeColor: colors[seriesIndex], size: 5, shape: "square" as const })),
        );
        const hasPoints = series.some((s) => s.data.some((d) => d.y !== null));
        const options: ApexCharts.ApexOptions = {
            chart: { type: "line", toolbar: { show: false }, zoom: { enabled: false }, parentHeightOffset: 0 },
            colors,
            stroke: { width: 2, curve: "straight" },
            markers: { size: 4, strokeWidth: 0, discrete },
            xaxis: {
                type: "datetime",
                labels: {
                    datetimeUTC: false,
                    style: { fontSize: "11px" },
                    formatter: (value: string) => new Date(Number(value)).toLocaleDateString("pt-PT", { day: "2-digit", month: "short" }),
                },
            },
            yaxis: { labels: { formatter: (v: number) => int(Math.round(v)) } },
            tooltip: { x: { format: "dd/MM/yyyy" }, y: { formatter: (v: number | null) => (v === null || v === undefined ? "Sem registo" : int(v)) } },
            legend: { position: "top", horizontalAlign: "left", fontSize: "12px" },
            grid: { borderColor: "var(--vz-border-color)", strokeDashArray: 3 },
            dataLabels: { enabled: false },
        };
        return { series, options, hasPoints };
    }, [data]);

    return (
        <Card className="mb-3">
            <CardHeader className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h5 className="card-title mb-1">Seguidores</h5>
                    <p className="text-muted fs-13 mb-0">Os seguidores atuais e o crescimento no tempo, por rede.</p>
                </div>
                <ButtonGroup size="sm" aria-label="Período do gráfico">
                    {WINDOWS.map((w) => (
                        <Button key={w} color="primary" outline={days !== w} onClick={() => setDays(w)}>{w === 365 ? "1 ano" : `${w} dias`}</Button>
                    ))}
                </ButtonGroup>
            </CardHeader>
            <CardBody>
                {loading && !data ? (
                    <div className="text-center py-4"><Spinner size="sm" color="primary" /></div>
                ) : error || !data ? (
                    <p className="text-muted mb-0">Não foi possível carregar os seguidores. Tente novamente dentro de momentos.</p>
                ) : (
                    <>
                        <div className="alert alert-info fs-13 py-2 mb-3" role="note">
                            <i className="ri-information-line me-1" />
                            A leitura automática dos seguidores ainda não está disponível: depende da ligação às redes e das permissões da Meta.
                            Até lá, registe os valores à mão; quando a leitura automática chegar, prevalece sobre o registo manual do mesmo dia.
                        </div>

                        <Row className="g-3 mb-3">
                            {PLATFORMS.map((p) => {
                                const current = data.platforms[p.key].current;
                                return (
                                    <Col md={6} key={p.key}>
                                        <div className="border rounded p-3 h-100">
                                            <div className="d-flex align-items-center gap-2 mb-2">
                                                <i className={`${p.icon} fs-4`} style={{ color: `var(${p.colorVar})` }} />
                                                <span className="fw-medium">{p.label}</span>
                                            </div>
                                            {current ? (
                                                <>
                                                    <div className="fs-20 fw-semibold">{int(current.count)} <span className="fs-13 fw-normal text-muted">seguidores</span></div>
                                                    <div className="text-muted fs-12 mb-2">{SOURCE_LABEL[current.source]}, em {dmy(current.date)}</div>
                                                </>
                                            ) : (
                                                <p className="text-muted fs-13 mb-2">Ainda sem registos.</p>
                                            )}
                                            {data.can_record && (
                                                <div className="d-flex gap-2">
                                                    <Input
                                                        type="number"
                                                        min={0}
                                                        step={1}
                                                        inputMode="numeric"
                                                        placeholder="Seguidores hoje"
                                                        aria-label={`Seguidores de hoje no ${p.label}`}
                                                        value={draft[p.key]}
                                                        onChange={(e) => setDraft((d) => ({ ...d, [p.key]: e.target.value }))}
                                                    />
                                                    <Button color="primary" className="text-nowrap" onClick={() => save(p.key)} disabled={saving !== null}>
                                                        {saving === p.key && <Spinner size="sm" className="me-1" />}Registar
                                                    </Button>
                                                </div>
                                            )}
                                        </div>
                                    </Col>
                                );
                            })}
                        </Row>

                        {chart && chart.hasPoints ? (
                            <>
                                <ReactApexChart options={chart.options} series={chart.series} type="line" height={300} />
                                <p className="text-muted fs-12 mb-0">
                                    Os dias sem registo ficam em branco (não se inventam valores). Os pontos quadrados foram registados à mão; os redondos vêm da leitura automática.
                                </p>
                            </>
                        ) : (
                            <p className="text-muted fs-13 mb-0">Ainda não há registos neste período para desenhar o crescimento.</p>
                        )}
                        {!data.can_record && (
                            <p className="text-muted fs-12 mb-0 mt-2">Só o administrador da empresa pode registar os seguidores.</p>
                        )}
                    </>
                )}
            </CardBody>
        </Card>
    );
}
