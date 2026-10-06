import { useEffect, useMemo, useState } from "react";
import { Badge, Button, Col, Input, Label, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { savePostResults, skipPostNetwork } from "helpers/laravel_helper";
import { Network, POST_CHANNEL_META, mediaFormatLabel } from "common/models/editorialPost.model";
import { METRICS, METRIC_SOURCE_LABEL, PostNetworkDetail, PostResults, PostWorkflow, fmtRate } from "common/models/editorialWorkflow.model";
import MarkPublishedModal from "../MarkPublishedModal";

/**
 * Publicação e Análise, por rede: marcar como publicada (link e hora real) ou "Não
 * publicar nesta rede" (com o motivo; não pede nova aprovação); os números à mão de cada
 * rede, com a data da medição e a origem; a taxa de envolvimento; e as notas de
 * aprendizagem da publicação. "Publicado" só quando nenhuma rede está por publicar.
 */

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "");
const todayIso = () => new Date().toLocaleDateString("sv-SE", { timeZone: "Europe/Lisbon" });
const errorsOf = (e: any) => (e?.errors ? Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, String((v as string[])[0])])) : null);

function NetworkResults({ companyId, postId, network, r, canRecord, onChanged }: { companyId: number; postId: number; network: Network; r: PostResults | undefined; canRecord: boolean; onChanged: (d: PostWorkflow) => void }) {
    const [values, setValues] = useState<Record<string, string>>({});
    const [measuredOn, setMeasuredOn] = useState(todayIso());
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        setValues(Object.fromEntries(METRICS.map((m) => [m.key, r?.values[m.key]?.source === "manual" ? String(r.values[m.key]!.value) : ""])));
        setMeasuredOn(r?.measured_on ?? todayIso());
        setErrors({});
    }, [r]);

    const metrics = METRICS.filter((m) => m.key !== "video_views" || r?.is_video);
    const liveRate = useMemo(() => {
        const reach = Number(values.reach);
        return values.reach && reach > 0 && values.interactions !== "" && values.interactions !== undefined ? (Number(values.interactions) / reach) * 100 : null;
    }, [values]);
    const id = (k: string) => `mt-${network}-${k}`;

    const save = async () => {
        setBusy(true);
        setErrors({});
        try {
            const body: Record<string, unknown> = { network, measured_on: measuredOn };
            metrics.forEach((m) => { body[m.key] = values[m.key] === "" ? null : Number(values[m.key]); });
            const res: any = await savePostResults(companyId, postId, body);
            toast.success("Resultados guardados.");
            onChanged(res.data);
        } catch (e: any) {
            const f = errorsOf(e);
            if (f) setErrors(f); else toast.error(e?.message ?? "Não foi possível guardar os resultados.");
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="mt-3">
            <div className="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-2">
                <strong className="fs-13">Resultados</strong>
                <span className="fs-13">Envolvimento: <strong>{(canRecord ? liveRate : r?.engagement_rate ?? null) === null ? "sem alcance" : fmtRate(canRecord ? liveRate : r!.engagement_rate)}</strong></span>
            </div>
            <Row className="g-2">
                {metrics.map((m) => {
                    const stored = r?.values[m.key];
                    return (
                        <Col xs={6} md={3} key={m.key}>
                            <Label className="fs-12 mb-1" for={id(m.key)}>{m.label}</Label>
                            <Input id={id(m.key)} bsSize="sm" type="number" min={0} step={1} inputMode="numeric" disabled={!canRecord} invalid={!!errors[m.key]}
                                value={values[m.key] ?? ""} onChange={(e) => setValues((x) => ({ ...x, [m.key]: e.target.value }))} />
                            {stored && stored.source !== "manual" && <div className="fs-11 text-muted">{stored.value.toLocaleString("pt-PT")} <Badge color="info-subtle" className="text-info">{METRIC_SOURCE_LABEL[stored.source] ?? stored.source}</Badge></div>}
                            {errors[m.key] && <div className="text-danger fs-11">{errors[m.key]}</div>}
                        </Col>
                    );
                })}
                <Col xs={6} md={3}>
                    <Label className="fs-12 mb-1" for={id("date")}>Data da medição</Label>
                    <Input id={id("date")} bsSize="sm" type="date" max={todayIso()} disabled={!canRecord} invalid={!!errors.measured_on} value={measuredOn} onChange={(e) => setMeasuredOn(e.target.value)} />
                    {errors.measured_on && <div className="text-danger fs-11">{errors.measured_on}</div>}
                </Col>
            </Row>
            {canRecord && (
                <div className="d-flex align-items-center justify-content-between gap-2 mt-2">
                    <span className="text-muted fs-11">Números à mão, como aparecem nas estatísticas da rede (ficam marcados como "à mão").</span>
                    <Button color="primary" size="sm" disabled={busy || !measuredOn} onClick={() => void save()}>{busy ? <Spinner size="sm" /> : "Guardar resultados"}</Button>
                </div>
            )}
        </div>
    );
}

type Props = { companyId: number; data: PostWorkflow; onChanged: (d: PostWorkflow) => void };

export default function PublishSection({ companyId, data, onChanged }: Props) {
    const p = data.post;
    const [marking, setMarking] = useState<PostNetworkDetail | null>(null);
    const [skipping, setSkipping] = useState<Network | null>(null);
    const [reason, setReason] = useState("");
    const [busy, setBusy] = useState(false);
    const [worked, setWorked] = useState("");
    const [change, setChange] = useState("");
    useEffect(() => { setWorked(data.results?.worked ?? ""); setChange(data.results?.change ?? ""); }, [data]);

    const canMark = data.publishing.can_mark;
    const canRecord = !!data.results?.can_record;
    const publishable = ["scheduled", "published", "analysis"].includes(p.stage);

    const skip = async (network: Network) => {
        setBusy(true);
        try {
            const r: any = await skipPostNetwork(companyId, p.id, { network, reason: reason.trim() });
            toast.success("Registado: não publicar nesta rede.");
            setSkipping(null);
            setReason("");
            onChanged(r.data);
        } catch (e: any) {
            toast.error(errorsOf(e)?.reason ?? e?.message ?? "Não foi possível registar.");
        } finally {
            setBusy(false);
        }
    };

    const saveNotes = async () => {
        const first = p.networks.find((n) => n.state === "published");
        if (!first) return;
        setBusy(true);
        try {
            const r: any = await savePostResults(companyId, p.id, { network: first.network, worked, change });
            toast.success("Notas guardadas.");
            onChanged(r.data);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar as notas.");
        } finally {
            setBusy(false);
        }
    };

    if (!publishable) {
        return (
            <div className="text-muted fs-13 border rounded p-3">
                <i className="ri-information-line me-1" />A publicação e a análise ficam disponíveis depois de aprovada (etapa Programado). Antes disso, trate do conteúdo e da aprovação.
            </div>
        );
    }

    return (
        <div>
            {p.overdue && (
                <div className="alert alert-danger fs-13 py-2">
                    <i className="ri-alarm-warning-line me-1" /><strong>Atrasada.</strong> A data e a hora passaram e falta marcar como publicada
                    {" "}{p.networks.filter((n) => n.state === "pending").map((n) => `no ${POST_CHANNEL_META[n.network].label}`).join(" e ")}.
                </div>
            )}
            {p.networks.length > 1 && (
                <p className="fs-13 mb-2">{data.publishing.published_count} de {data.publishing.active_count} {data.publishing.active_count === 1 ? "rede publicada" : "redes publicadas"}{p.networks.some((n) => n.state === "skipped") ? " (sem contar as dispensadas)" : ""}.</p>
            )}
            {p.networks.map((n) => (
                <div key={n.network} className="border rounded p-3 mb-3">
                    <div className="d-flex flex-wrap align-items-center gap-2">
                        <h6 className="mb-0 text-uppercase fs-12" style={{ letterSpacing: "0.04em" }}><i className={`${POST_CHANNEL_META[n.network].icon} me-1`} />{POST_CHANNEL_META[n.network].label}{n.media_format ? ` · ${mediaFormatLabel(n.media_format)}` : ""}</h6>
                        {n.state === "published" && <Badge color="success-subtle" className="text-success">Publicada</Badge>}
                        {n.state === "skipped" && <Badge color="secondary-subtle" className="text-secondary">Não publicar</Badge>}
                        {n.state === "pending" && <Badge color={p.overdue ? "danger" : "warning-subtle"} className={p.overdue ? "" : "text-warning"}>{p.overdue ? "Atrasada" : "Por publicar"}</Badge>}
                        {canMark && (
                            <div className="ms-auto d-flex flex-wrap gap-1">
                                {n.state !== "published" && <Button size="sm" color="success" onClick={() => setMarking(n)}><i className="ri-checkbox-circle-line me-1" />Marcar como publicada</Button>}
                                {n.state === "published" && <Button size="sm" color="link" className="p-0" onClick={() => setMarking(n)}>Corrigir o link ou a hora</Button>}
                                {n.state === "pending" && p.networks.length > 1 && <Button size="sm" color="soft-secondary" onClick={() => { setSkipping(n.network); setReason(""); }}>Não publicar nesta rede</Button>}
                            </div>
                        )}
                    </div>
                    {n.state === "published" && (
                        <div className="fs-13 mt-2">
                            Publicada {when(n.published_at)}{" "}
                            {n.published_url && <a href={n.published_url} target="_blank" rel="noreferrer noopener" className="text-break"><i className="ri-external-link-line me-1" />{n.published_url}</a>}
                            {n.published_by && <div className="text-muted fs-12">Marcada por {n.published_by}</div>}
                        </div>
                    )}
                    {n.state === "skipped" && <div className="fs-13 mt-2 text-muted">Motivo: {n.skip_reason}{n.skipped_by ? ` (${n.skipped_by}, ${when(n.skipped_at)})` : ""}</div>}
                    {skipping === n.network && (
                        <div className="border rounded p-2 mt-2">
                            <Label className="fs-13 mb-1" for={`skip-${n.network}`}>Porque não vai sair no {POST_CHANNEL_META[n.network].label}?</Label>
                            <textarea id={`skip-${n.network}`} className="form-control mb-2" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
                            <div className="d-flex gap-2">
                                <Button size="sm" color="secondary" disabled={busy || reason.trim().length < 3} onClick={() => void skip(n.network)}>Registar</Button>
                                <Button size="sm" color="light" onClick={() => setSkipping(null)}>Cancelar</Button>
                            </div>
                            <div className="form-text">Não pede nova aprovação. Fica no histórico, com o motivo.</div>
                        </div>
                    )}
                    {n.state === "published" && <NetworkResults companyId={companyId} postId={p.id} network={n.network} r={data.results?.networks[n.network]} canRecord={canRecord} onChanged={onChanged} />}
                </div>
            ))}

            {data.results && (
                <div className="border rounded p-3">
                    <h6 className="mb-2">Aprendizagem</h6>
                    <Row className="g-2">
                        <Col md={6}><Label className="fs-12 mb-1" for="pn-worked">O que funcionou <span className="text-muted">(opcional)</span></Label>
                            <textarea id="pn-worked" className="form-control form-control-sm" rows={2} maxLength={2000} disabled={!canRecord} value={worked} onChange={(e) => setWorked(e.target.value)} /></Col>
                        <Col md={6}><Label className="fs-12 mb-1" for="pn-change">O que mudar <span className="text-muted">(opcional)</span></Label>
                            <textarea id="pn-change" className="form-control form-control-sm" rows={2} maxLength={2000} disabled={!canRecord} value={change} onChange={(e) => setChange(e.target.value)} /></Col>
                    </Row>
                    {canRecord && <div className="d-flex justify-content-end mt-2"><Button size="sm" color="primary" disabled={busy} onClick={() => void saveNotes()}>Guardar notas</Button></div>}
                </div>
            )}

            <MarkPublishedModal isOpen={!!marking} toggle={() => setMarking(null)} companyId={companyId}
                post={marking ? { id: p.id, title: p.title, channel: marking.network } : null} network={marking?.network ?? "instagram"}
                initialUrl={marking?.published_url} initialAt={marking?.published_at} onDone={(d) => onChanged(d)} />
        </div>
    );
}
