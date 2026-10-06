import { useEffect, useMemo, useState } from "react";
import { Badge, Button, Col, Input, Label, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { savePostResults } from "helpers/laravel_helper";
import { METRICS, METRIC_SOURCE_LABEL, MetricKey, PostWorkflow, fmtRate } from "common/models/editorialWorkflow.model";

/**
 * Publicação e resultados (F3d), na janela de produção: o link e a hora real (com quem
 * marcou) e os números à mão, com a data da medição e a origem de cada um; a taxa de
 * envolvimento (interações ÷ alcance × 100) calculada; notas de aprendizagem.
 */

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "");
const todayIso = () => new Date().toLocaleDateString("sv-SE", { timeZone: "Europe/Lisbon" });

type Props = { data: PostWorkflow; companyId: number; onChanged: (d: PostWorkflow) => void; onEditPublished: () => void };

export default function PostResultsSection({ data, companyId, onChanged, onEditPublished }: Props) {
    const r = data.results;
    const pub = data.publishing;
    const [values, setValues] = useState<Record<string, string>>({});
    const [measuredOn, setMeasuredOn] = useState(todayIso());
    const [worked, setWorked] = useState("");
    const [change, setChange] = useState("");
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!r) return;
        // Os campos mostram só os números à mão (os lidos da Meta, na F6, aparecem ao lado).
        setValues(Object.fromEntries(METRICS.map((m) => [m.key, r.values[m.key]?.source === "manual" ? String(r.values[m.key]!.value) : ""])));
        setMeasuredOn(r.measured_on ?? todayIso());
        setWorked(r.worked ?? "");
        setChange(r.change ?? "");
        setErrors({});
    }, [r]);

    const metrics = METRICS.filter((m) => m.key !== "video_views" || r?.is_video);
    const liveRate = useMemo(() => {
        const reach = Number(values.reach);
        const inter = values.interactions === "" || values.interactions === undefined ? null : Number(values.interactions);
        return values.reach && reach > 0 && inter !== null ? (inter / reach) * 100 : null;
    }, [values]);

    if (!r) return null;
    const canRecord = r.can_record;

    const save = async () => {
        setBusy(true);
        setErrors({});
        try {
            const body: Record<string, unknown> = { measured_on: measuredOn, worked, change };
            metrics.forEach((m) => { body[m.key] = values[m.key] === "" ? null : Number(values[m.key]); });
            const res: any = await savePostResults(companyId, data.post.id, body);
            toast.success("Resultados guardados.");
            onChanged(res.data);
        } catch (e: any) {
            if (e?.errors) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, String((v as string[])[0])])));
            else toast.error(e?.message ?? "Não foi possível guardar os resultados.");
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="border rounded p-3 mb-3">
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <h6 className="mb-0"><i className="ri-checkbox-circle-line text-success me-1" />Publicada {when(pub.published_at)}</h6>
                {pub.can_mark && <Button size="sm" color="link" className="p-0" onClick={onEditPublished}>Corrigir o link ou a hora</Button>}
            </div>
            <div className="fs-13 mb-3">
                {pub.url ? <a href={pub.url} target="_blank" rel="noreferrer noopener" className="text-break"><i className="ri-external-link-line me-1" />{pub.url}</a> : <span className="text-muted">Sem link registado.</span>}
                {pub.by && <div className="text-muted fs-12">Marcada por {pub.by}</div>}
            </div>

            <div className="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-2">
                <h6 className="mb-0">Resultados</h6>
                <span className="fs-13">
                    Taxa de envolvimento: <strong>{canRecord ? (liveRate === null ? "sem alcance" : fmtRate(liveRate)) : (r.engagement_rate === null ? "sem alcance" : fmtRate(r.engagement_rate))}</strong>
                </span>
            </div>
            <Row className="g-2">
                {metrics.map((m) => {
                    const stored = r.values[m.key as MetricKey];
                    return (
                        <Col xs={6} md={3} key={m.key}>
                            <Label className="fs-12 mb-1" for={`mt-${m.key}`}>{m.label}</Label>
                            <Input id={`mt-${m.key}`} bsSize="sm" type="number" min={0} step={1} inputMode="numeric" disabled={!canRecord} invalid={!!errors[m.key]}
                                value={values[m.key] ?? ""} onChange={(e) => setValues((v) => ({ ...v, [m.key]: e.target.value }))} />
                            {stored && stored.source !== "manual" && (
                                <div className="fs-11 text-muted">{stored.value.toLocaleString("pt-PT")} <Badge color="info-subtle" className="text-info">{METRIC_SOURCE_LABEL[stored.source] ?? stored.source}</Badge></div>
                            )}
                            {errors[m.key] && <div className="text-danger fs-11">{errors[m.key]}</div>}
                        </Col>
                    );
                })}
            </Row>
            <Row className="g-2 mt-1">
                <Col md={4}>
                    <Label className="fs-12 mb-1" for="mt-date">Data da medição</Label>
                    <Input id="mt-date" bsSize="sm" type="date" max={todayIso()} disabled={!canRecord} invalid={!!errors.measured_on} value={measuredOn} onChange={(e) => setMeasuredOn(e.target.value)} />
                    {errors.measured_on && <div className="text-danger fs-11">{errors.measured_on}</div>}
                </Col>
                <Col md={8} className="d-flex align-items-end">
                    <span className="text-muted fs-11">Números à mão, tal como aparecem nas estatísticas da rede. Ficam marcados como "à mão", para não se confundirem com os lidos automaticamente.</span>
                </Col>
                <Col md={6}>
                    <Label className="fs-12 mb-1" for="mt-worked">O que funcionou <span className="text-muted">(opcional)</span></Label>
                    <textarea id="mt-worked" className="form-control form-control-sm" rows={2} maxLength={2000} disabled={!canRecord} value={worked} onChange={(e) => setWorked(e.target.value)} />
                </Col>
                <Col md={6}>
                    <Label className="fs-12 mb-1" for="mt-change">O que mudar <span className="text-muted">(opcional)</span></Label>
                    <textarea id="mt-change" className="form-control form-control-sm" rows={2} maxLength={2000} disabled={!canRecord} value={change} onChange={(e) => setChange(e.target.value)} />
                </Col>
            </Row>
            {canRecord && (
                <div className="mt-2 d-flex justify-content-end">
                    <Button color="primary" size="sm" disabled={busy || !measuredOn} onClick={() => void save()}>
                        {busy ? <Spinner size="sm" /> : <><i className="ri-save-line me-1" />Guardar resultados</>}
                    </Button>
                </div>
            )}
        </div>
    );
}
