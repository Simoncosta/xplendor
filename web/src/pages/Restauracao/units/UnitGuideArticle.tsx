import { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { Alert, Badge, Button, Card, CardBody, Col, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import InfoTip from "Components/Common/InfoTip";
import ReasonButton from "Components/Common/ReasonButton";
import HiddenMoney from "Components/Common/HiddenMoney";
import {
    applyUnitGuideStep, askAiUnitGuide, confirmUnitGuide, getUnitGuideWrite, infoUnitGuide, notApplicableUnitGuide, sendAdminUnitGuide,
} from "helpers/laravel_helper";
import { UnitGuideView, UnitQuestion, UnitWrite } from "common/models/unitGuide.model";
import RecipeEditorModal, { RecipePrefill } from "pages/Restauracao/fichas/RecipeEditor";
import "./unitGuide.css";

/**
 * XPLENDOR — Auditoria v2: um artigo no Guião de unidades, em passos verticais (no telemóvel,
 * uns por baixo dos outros): o que vimos, as perguntas, a proposta, o que muda, decidir; nos
 * "Por identificar", as três saídas; depois "Aplicar no PingWin" e "Converter as fichas". Nada
 * passa a confirmado sem carregar em "Confirmar"; o PingWin só muda num passo de cada vez.
 */
const eur = (v: number | null | undefined, d = 2) => (v === null || v === undefined ? "" : v.toLocaleString("pt-PT", { minimumFractionDigits: d, maximumFractionDigits: d }) + " €");
const STATE_LABEL: Record<string, [string, string]> = {
    por_identificar: ["Por identificar", "warning"], sugerido: ["Por confirmar", "info"], confirmado: ["Confirmado na XPLENDOR", "success"],
    aplicado_no_pingwin: ["Aplicado no PingWin", "success"], nao_se_aplica: ["Não se aplica", "secondary"],
};
const FINAL = ["ok", "mismatch", "refused", "failed"];
/** O veredicto: a cor, o ícone e o tom do título. */
const VERDICT: Record<string, { tone: string; text: string; icon: string }> = {
    certo: { tone: "success", text: "success", icon: "ri-checkbox-circle-line" },
    mudar: { tone: "warning", text: "warning", icon: "ri-error-warning-line" },
    ajuda: { tone: "grey", text: "body", icon: "ri-question-line" },
};
/** A fonte de cada linha de "O que vimos". */
const SOURCE: Record<string, { label: string; icon: string; cls: string }> = {
    faturas: { label: "Faturas", icon: "ri-file-list-3-line", cls: "bg-primary-subtle text-primary" },
    fichas: { label: "Fichas", icon: "ri-restaurant-line", cls: "bg-success-subtle text-success" },
    contagens: { label: "Contagens", icon: "ri-scales-3-line", cls: "bg-info-subtle text-info" },
    pingwin: { label: "PingWin", icon: "ri-database-2-line", cls: "bg-secondary-subtle text-secondary" },
};

function Step({ n, title, tip, children, testid, tone, id }: { n: number | string; title: string; tip?: string; children: React.ReactNode; testid: string; tone?: string; id?: string }) {
    return (
        <Card id={id} className={`mb-3 ${tone ? `border-${tone}` : ""}`} data-testid={testid}>
            <CardBody className="p-3 p-md-4">
                <div className="d-flex align-items-center gap-2 mb-2">
                    <span className="badge rounded-pill bg-primary-subtle text-primary">{n}</span>
                    <h6 className="mb-0 fs-14 text-uppercase fw-semibold text-muted">{title}</h6>
                    {tip && <InfoTip label={title} text={tip} />}
                </div>
                {children}
            </CardBody>
        </Card>
    );
}

export function Questions({ questions, answers, onAnswer, disabled, unknown }: {
    questions: UnitQuestion[]; answers: Record<string, string | null>; onAnswer: (id: string, value: string | null) => void; disabled?: boolean; unknown?: boolean;
}) {
    if (!questions.length) {
        return <p className="text-muted mb-0">{unknown ? "Não há perguntas a fazer com estes dados: dê mais informação, peça uma sugestão ou envie ao administrador." : "Não há perguntas: as evidências chegam."}</p>;
    }
    return (
        <div className="d-flex flex-column gap-3">
            {questions.map((q) => {
                const value = answers[q.id] ?? q.answer ?? "";
                return (
                    <fieldset key={q.id} data-testid={`question-${q.id}`}>
                        <legend className="fs-14 fw-semibold mb-2">{q.text}</legend>
                        {q.kind === "choice" && (
                            <div className="d-flex flex-wrap gap-2">
                                {q.options.map((o) => (
                                    <Button key={o.value} size="sm" type="button" color={value === o.value ? "primary" : "light"} aria-pressed={value === o.value}
                                        onClick={() => onAnswer(q.id, o.value)} disabled={disabled}>
                                        {o.label}{!value && q.default === o.value ? " (sugerido)" : ""}
                                    </Button>
                                ))}
                            </div>
                        )}
                        {q.kind === "number" && (
                            <Input type="text" inputMode="decimal" style={{ maxWidth: 200 }} aria-label={q.text} value={value ?? ""} disabled={disabled}
                                onChange={(e) => onAnswer(q.id, e.target.value || null)} placeholder="Ex.: 100" />
                        )}
                        {q.kind === "quantity" && <QuantityAnswer q={q} value={value ?? ""} onAnswer={onAnswer} disabled={disabled} />}
                    </fieldset>
                );
            })}
        </div>
    );
}

function QuantityAnswer({ q, value, onAnswer, disabled }: { q: UnitQuestion; value: string; onAnswer: (id: string, v: string | null) => void; disabled?: boolean }) {
    const [num, unit] = value.includes("|") ? value.split("|") : [value, "kg"];
    return (
        <div className="d-flex gap-2" style={{ maxWidth: 320 }}>
            <Input type="text" inputMode="decimal" aria-label={q.text} value={num} disabled={disabled} placeholder="Ex.: 5"
                onChange={(e) => onAnswer(q.id, e.target.value ? `${e.target.value}|${unit}` : null)} />
            <Input type="select" aria-label="Unidade" value={unit} disabled={disabled} onChange={(e) => onAnswer(q.id, num ? `${num}|${e.target.value}` : null)}>
                {(q.units ?? [["kg", "kg"], ["L", "litros"], ["un", "unidades"]]).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </Input>
        </div>
    );
}

export default function UnitGuideArticle({ companyId, view, answers, setAnswers, previewing, canEdit, editReason, onChanged, onSkip, onNext }: {
    companyId: number; view: UnitGuideView; answers: Record<string, string | null>; setAnswers: (a: Record<string, string | null>) => void;
    previewing: boolean; canEdit: boolean; editReason: string | null; onChanged: (v?: UnitGuideView) => void; onSkip: () => void; onNext: () => void;
}) {
    const hidden = view.financial?.visible === false;
    const p = view.proposal;
    const i = view.impact;
    const pid = view.article.product_id;
    const [busy, setBusy] = useState<string | null>(null);
    const [naOpen, setNaOpen] = useState(false);
    const [naReason, setNaReason] = useState("");
    const [infoText, setInfoText] = useState("");
    const [photo, setPhoto] = useState<File | null>(null);
    const [adminNote, setAdminNote] = useState("");
    const [confirmStep, setConfirmStep] = useState<number | null>(null);
    const [write, setWrite] = useState<UnitWrite | null>(null);
    const [prefill, setPrefill] = useState<{ productId: string; bomId: string; prefill: RecipePrefill } | null>(null);
    const timer = useRef<ReturnType<typeof setInterval> | null>(null);
    const money = (v: number | null | undefined, seed = 0, d = 2) => (hidden ? <HiddenMoney seed={seed} /> : eur(v, d));

    useEffect(() => () => { if (timer.current) clearInterval(timer.current); }, []);

    const run = async (key: string, fn: () => Promise<any>, ok: string) => {
        setBusy(key);
        try {
            const res: any = await fn();
            toast.success(res?.message ?? ok);
            onChanged(res?.data?.article ? res.data : undefined);
            return res;
        } catch (e: any) {
            const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
            toast.error((first as string) ?? e?.message ?? "Não foi possível guardar.");
            return null;
        } finally {
            setBusy(null);
        }
    };

    const confirm = () => run("confirm", () => confirmUnitGuide(companyId, pid, answers), "Confirmado na XPLENDOR.");
    const notApplicable = async () => {
        const r = await run("na", () => notApplicableUnitGuide(companyId, pid, naReason), "Marcado como «Não se aplica».");
        if (r) { setNaOpen(false); setNaReason(""); onNext(); }
    };
    const sendInfo = async () => {
        const fd = new FormData();
        if (infoText.trim()) fd.append("text", infoText.trim());
        if (photo) fd.append("photo", photo);
        fd.append("answers", JSON.stringify(answers));
        const r = await run("info", () => infoUnitGuide(companyId, pid, fd), "Informação guardada: o artigo foi reavaliado.");
        if (r) { setInfoText(""); setPhoto(null); }
    };
    const askAi = () => run("ai", () => askAiUnitGuide(companyId, pid), "Sugestão automática, a confirmar.");
    const sendAdmin = () => run("admin", () => sendAdminUnitGuide(companyId, pid, adminNote || undefined), "Enviado ao administrador.");

    const apply = async (index: number) => {
        setConfirmStep(null);
        setBusy("apply");
        try {
            const res: any = await applyUnitGuideStep(companyId, pid, index);
            const w: UnitWrite = res?.data?.write;
            setWrite(w);
            timer.current = setInterval(async () => {
                try {
                    const r: any = await getUnitGuideWrite(companyId, w.id);
                    const now: UnitWrite = r?.data?.write;
                    setWrite(now);
                    if (FINAL.includes(now.status)) {
                        if (timer.current) clearInterval(timer.current);
                        setBusy(null);
                        if (now.status === "ok") toast.success("Gravado no PingWin e confirmado pela releitura.");
                        else toast.error(now.error ?? now.note ?? "O PingWin não confirmou a alteração.");
                        onChanged();
                    }
                } catch { /* tenta outra vez */ }
            }, 1500);
        } catch (e: any) {
            const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
            toast.error((first as string) ?? e?.message ?? "Não foi possível gravar no PingWin.");
            setBusy(null);
        }
    };

    const answer = (id: string, value: string | null) => setAnswers({ ...answers, [id]: value });
    const decided = ["confirmado", "aplicado_no_pingwin"].includes(view.profile?.state ?? "");
    const [stateLabel, stateTone] = STATE_LABEL[view.profile?.state ?? (view.por_identificar ? "por_identificar" : "sugerido")] ?? STATE_LABEL.sugerido;
    const confirmReason = editReason ?? (!p.identified ? "Responda às perguntas: o artigo ainda está por identificar." : !p.complete ? "Falta dizer quanto leva alguma unidade de compra." : null);

    return (
        <div data-testid="guide-article">
            <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
                <h5 className="mb-0">{view.article.label}</h5>
                <Badge color={stateTone} className={`bg-${stateTone}-subtle text-${stateTone}`} data-testid="guide-state">{stateLabel}</Badge>
                <span className="text-muted fs-12 d-inline-flex align-items-center gap-1" data-testid="guide-confidence">
                    Confiança {view.confidence_label.label.toLowerCase()}<InfoTip label="Confiança" text={view.confidence_label.why} />
                </span>
                {previewing && <Spinner size="sm" aria-label="A atualizar a proposta" />}
                <Link to={view.article.url} className="ms-auto fs-13">Abrir o artigo</Link>
            </div>

            {/* A conclusão antes das evidências. */}
            <Card className={`mb-3 xp-verdict xp-verdict-${view.verdict.key}`} data-testid="guide-verdict">
                <CardBody className="p-3 p-md-4 d-flex flex-column flex-md-row align-items-md-center gap-3">
                    <i className={`${VERDICT[view.verdict.key].icon} fs-2 xp-verdict-icon`} aria-hidden />
                    <div style={{ flex: "1 1 0", minWidth: 0 }}>
                        <div className="fs-16 fw-semibold xp-verdict-title" data-testid="guide-verdict-title">{view.verdict.title}</div>
                        <div className="fs-14 text-body" data-testid="guide-verdict-text">{view.verdict.text}</div>
                    </div>
                    {view.verdict.key !== "ajuda" && !decided && (
                        <ReasonButton type="button" color={view.verdict.key === "certo" ? "success" : "primary"} reason={confirmReason} onClick={confirm} disabled={busy !== null}>
                            {busy === "confirm" ? <Spinner size="sm" className="me-1" /> : <i className="ri-check-line me-1" aria-hidden />}Confirmar
                        </ReasonButton>
                    )}
                    {view.verdict.key === "ajuda" && <a href="#guide-unknown" className="btn btn-sm btn-light align-self-start align-self-md-center">Ver as três saídas</a>}
                </CardBody>
            </Card>

            <Step n={1} title="O que vimos" testid="guide-seen" tip="As compras dos últimos 12 meses (faturas lançadas no PingWin), a embalagem lida na descrição do fornecedor, o uso nas fichas, as contagens e as transferências, e o preço face à família. O nome do artigo nunca decide.">
                <ul className="list-unstyled mb-2">
                    {view.seen.map((s, k) => (
                        <li key={k} className="d-flex align-items-start gap-2 mb-2" data-testid={`guide-seen-${s.source}`}>
                            <span className={`badge ${SOURCE[s.source].cls} d-inline-flex align-items-center gap-1 flex-shrink-0`} style={{ minWidth: 92 }}>
                                <i className={SOURCE[s.source].icon} aria-hidden />{SOURCE[s.source].label}
                            </span>
                            <span>{s.text}</span>
                        </li>
                    ))}
                </ul>
                {view.evidence.purchases.some((x) => x.document_url) && (
                    <div className="d-flex flex-wrap gap-2 fs-13">
                        {view.evidence.purchases.filter((x) => x.document_url).map((x, k) => (
                            <Link key={k} to={x.document_url!} className="badge bg-light text-body border">{x.last_document ?? "Documento"} · {x.supplier}</Link>
                        ))}
                    </div>
                )}
            </Step>

            <Step n={2} title="Perguntas" testid="guide-questions">
                <Questions questions={p.questions} answers={answers} onAnswer={answer} disabled={!canEdit} unknown={view.por_identificar} />
            </Step>

            <Step n={3} title="A proposta" testid="guide-proposal" tone={!p.identified ? "warning" : undefined}>
                {!p.identified ? (
                    <Alert color="warning" className="mb-0 fs-13">Ainda por identificar: {p.conflicts.length ? p.conflicts.join(" ") : "responda às perguntas acima."}</Alert>
                ) : (
                    <>
                        <Row className="g-3 mb-2">
                            <Col xs={12} md={4}><div className="text-muted fs-12">Tipo</div><div className="fw-semibold">{p.type_label}</div></Col>
                            <Col xs={12} md={4}><div className="text-muted fs-12">Unidade base</div><div className="fw-semibold" data-testid="guide-base">{p.base_label}</div>
                                {p.current_base_label && p.base_change && <div className="fs-12 text-muted">Hoje: {p.current_base_label}</div>}</Col>
                            <Col xs={12} md={4}><div className="text-muted fs-12">Unidade de contagem</div><div className="fw-semibold">{p.count_unit?.label ?? "Sem contagem"}</div></Col>
                        </Row>
                        {p.purchase_units.length > 0 && (
                            <Table size="sm" responsive className="mb-2 fs-13">
                                <thead><tr><th>Fornecedor</th><th>Faturado em</th><th>Proposta</th><th>Porquê</th></tr></thead>
                                <tbody>
                                    {p.purchase_units.map((u, k) => (
                                        <tr key={k}><td>{u.supplier}</td><td>{u.unit_label}{u.pingwin_factor !== null ? <span className="text-muted"> (fator no PingWin {u.pingwin_factor.toLocaleString("pt-PT")})</span> : null}</td>
                                            <td className={u.factor ? "fw-semibold" : "text-warning"}>{u.label}</td><td className="text-muted">{u.why}</td></tr>
                                    ))}
                                </tbody>
                            </Table>
                        )}
                        {p.deactivate.length > 0 && <div className="fs-13 mb-1">Deixar de usar (sem uso há 12 meses; ficam no PingWin, são de todos os artigos): {p.deactivate.join(", ")}.</div>}
                        {p.learned && <div className="fs-13 text-muted"><i className="ri-lightbulb-line me-1" aria-hidden />{p.learned}</div>}
                    </>
                )}
                {view.ai.suggestion && (
                    <div className="border rounded p-2 mt-2 fs-13 bg-light-subtle" data-testid="guide-ai-suggestion">
                        <Badge color="info" className="bg-info-subtle text-info me-1">{view.ai.suggestion.label}</Badge>
                        {view.ai.suggestion.type_label}, base {view.ai.suggestion.base_name}{view.ai.suggestion.packaging ? `, ${view.ai.suggestion.packaging}` : ""}
                        {view.ai.suggestion.purchase_units.length > 0 && <> · {view.ai.suggestion.purchase_units.map((u) => `${u.unit} = ${u.factor.toLocaleString("pt-PT")}`).join("; ")}</>}
                        <div className="text-muted">{view.ai.suggestion.reason} (confiança {view.ai.suggestion.confidence.toLocaleString("pt-PT")})</div>
                    </div>
                )}
            </Step>

            {p.identified && view.verdict.key === "certo" && (
                <Step n={4} title="O que muda" testid="guide-impact">
                    <p className="mb-0" data-testid="guide-nothing-changes">Nada muda nas fichas, no stock nem nas faturas.</p>
                </Step>
            )}
            {p.identified && view.verdict.key !== "certo" && (
                <Step n={4} title="O que muda" testid="guide-impact" tip="Com números reais: as fichas (quantidade e custo por porção antes e depois), o stock e o PCM convertidos, como lançar as próximas faturas, como contar e quanto por mês a decisão corrige.">
                    {p.base_change_warning && (
                        <Alert color="danger" className="fs-13" data-testid="guide-base-warning">
                            <strong>{p.base_change_warning.title}.</strong> {p.base_change_warning.text}
                            <div className="mt-1">O caminho seguro:</div>
                            <ol className="mb-0 ps-3">{p.base_change_warning.paths.map((x, k) => <li key={k}>{x}</li>)}</ol>
                        </Alert>
                    )}
                    {i.recipes_note && <Alert color="info" className="fs-13 py-2">{i.recipes_note}</Alert>}
                    {i.recipes.length > 0 && (
                        <Table size="sm" responsive className="fs-13 mb-2" data-testid="guide-impact-recipes">
                            <thead><tr><th>Ficha</th><th>Antes</th><th>Depois</th><th className="text-end">Custo por porção</th></tr></thead>
                            <tbody>
                                {i.recipes.map((r, k) => (
                                    <tr key={k}><td>{r.label}</td><td>{r.before}</td><td className="fw-semibold">{r.after ?? "?"}</td>
                                        <td className="text-end">{r.cost_before_eur !== null || hidden ? <>{money(r.cost_before_eur, k, 4)} → {money(r.cost_after_eur, k + 1, 4)}</> : ""}</td></tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                    {i.stock.length > 0 && (
                        <div className="fs-13 mb-2">Stock: {i.stock.map((s) => `${s.before} → ${s.after ?? "?"}`).join("; ")}
                            {i.stock[0].pcm_before_eur !== null && <> · PCM {money(i.stock[0].pcm_before_eur, 5, 4)} → {money(i.stock[0].pcm_after_eur, 6, 4)}</>}</div>
                    )}
                    <ul className="fs-13 mb-2 ps-3">{i.invoices.map((x, k) => <li key={k}>{x}</li>)}{i.count && <li>{i.count}</li>}</ul>
                    <div className="d-flex flex-wrap gap-4">
                        <div><div className="text-muted fs-12 d-flex align-items-center gap-1">Corrige por mês<InfoTip label="Corrige por mês" text="O € em jogo nos achados de unidades deste artigo (90 dias), a dividir por 3." /></div>
                            <div className="fs-5 fw-semibold" data-testid="guide-monthly">{money(i.monthly_eur, 7)}</div></div>
                        {i.recipes_monthly_delta_eur !== 0 && (
                            <div><div className="text-muted fs-12 d-flex align-items-center gap-1">Custo das fichas por mês<InfoTip label="Custo das fichas por mês" text="A diferença no custo das porções vendidas (90 dias ÷ 3) com a unidade nova." /></div>
                                <div className="fs-5 fw-semibold">{money(i.recipes_monthly_delta_eur, 8)}</div></div>
                        )}
                    </div>
                </Step>
            )}

            {/* As ações sempre à vista: uma barra fixa no fundo. */}
            <div className="xp-guide-actions" data-testid="guide-decide">
                <div className="d-flex flex-wrap align-items-center gap-2">
                    <ReasonButton type="button" color="primary" reason={confirmReason} onClick={confirm} disabled={busy !== null || decided} data-testid="guide-confirm">
                        {busy === "confirm" ? <Spinner size="sm" className="me-1" /> : <i className="ri-check-line me-1" aria-hidden />}Confirmar
                    </ReasonButton>
                    <Button type="button" color="light" onClick={() => document.querySelector<HTMLElement>("[data-testid='guide-questions'] button, [data-testid='guide-questions'] input")?.focus()} disabled={!canEdit}>Ajustar</Button>
                    <Button type="button" color="light" onClick={onSkip} disabled={busy !== null} data-testid="guide-skip">Saltar</Button>
                    <ReasonButton type="button" color="outline-secondary" reason={editReason} onClick={() => setNaOpen(true)} disabled={busy !== null}>Não se aplica</ReasonButton>
                    <span className="text-muted fs-12 ms-auto d-none d-md-inline">
                        {decided && view.profile?.confirmed_by ? `Confirmado por ${view.profile.confirmed_by}.` : "Confirmar grava só na XPLENDOR. O PingWin não muda."}
                    </span>
                </div>
            </div>

            {view.por_identificar && (
                <Step n="?" id="guide-unknown" title="Por identificar: três saídas" testid="guide-unknown" tone="warning">
                    <Row className="g-3">
                        <Col xs={12} lg={4}>
                            <Label for="guide-info" className="fw-semibold">Dar mais informação</Label>
                            <Input id="guide-info" type="textarea" rows={3} value={infoText} onChange={(e) => setInfoText(e.target.value)} disabled={!canEdit}
                                placeholder="Ex.: cada saco tem 5 kg; usa-se ao grama." />
                            <Input type="file" accept="image/*" className="mt-2" aria-label="Foto (opcional)" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} disabled={!canEdit} />
                            <div className="text-muted fs-12 mt-1">A foto fica privada. O artigo é reavaliado logo.</div>
                            <ReasonButton type="button" size="sm" color="primary" className="mt-2" reason={editReason ?? (!infoText.trim() && !photo ? "Escreva o que sabe ou junte uma foto." : null)}
                                onClick={sendInfo} disabled={busy !== null} data-testid="guide-info-send">
                                {busy === "info" && <Spinner size="sm" className="me-1" />}Guardar e reavaliar
                            </ReasonButton>
                            {view.profile?.photo_url && <div className="mt-2"><a href={view.profile.photo_url} target="_blank" rel="noreferrer" className="fs-13">Ver a foto enviada</a></div>}
                        </Col>
                        <Col xs={12} lg={4}>
                            <div className="fw-semibold mb-1">Pedir sugestão</div>
                            <p className="text-muted fs-13 mb-2">Envia só os dados do artigo (descrições, unidades, preços, fichas), nunca dados pessoais. A resposta aparece como sugestão, a confirmar.</p>
                            <ReasonButton type="button" size="sm" color="outline-primary" reason={editReason ?? (view.ai.available ? null : view.ai.reason)} onClick={askAi} disabled={busy !== null} data-testid="guide-ai">
                                {busy === "ai" ? <Spinner size="sm" className="me-1" /> : <i className="ri-sparkling-line me-1" aria-hidden />}Pedir sugestão
                            </ReasonButton>
                        </Col>
                        <Col xs={12} lg={4}>
                            <Label for="guide-admin" className="fw-semibold">Enviar ao administrador</Label>
                            <Input id="guide-admin" type="text" value={adminNote} onChange={(e) => setAdminNote(e.target.value)} placeholder="Nota (opcional)" disabled={!canEdit} />
                            <ReasonButton type="button" size="sm" color="outline-secondary" className="mt-2" reason={editReason} onClick={sendAdmin} disabled={busy !== null} data-testid="guide-admin-send">
                                {busy === "admin" && <Spinner size="sm" className="me-1" />}Enviar ao administrador
                            </ReasonButton>
                            {view.findings.some((f) => f.assigned_to === "administrador") && <div className="fs-12 text-muted mt-1">Já está na fila do administrador.</div>}
                        </Col>
                    </Row>
                </Step>
            )}

            {decided && (
                <Step n={6} title="No PingWin" testid="guide-apply" tip="Uma alteração de cada vez, com confirmação e releitura. As unidades do PingWin são de todos os artigos e a quantidade não muda: a XPLENDOR reutiliza a unidade certa (ou cria-a, se não existir) e põe-na como unidade de compra; a antiga deixa de se usar, nunca se anula. A unidade base nunca muda pela XPLENDOR.">
                    {view.apply.steps.length > 0 && (
                        <ul className="list-unstyled mb-2">
                            {view.apply.steps.map((s, k) => (
                                <li key={k} className="d-flex flex-wrap align-items-center gap-2 py-1 border-bottom border-light-subtle">
                                    <span className="flex-grow-1">{s.label}</span>
                                    {s.status === "ok" ? <Badge color="success" className="bg-success-subtle text-success">Feito</Badge> : (
                                        <ReasonButton type="button" size="sm" color="primary" reason={view.apply.can_apply ? null : (view.apply.guard?.reason ?? "Disponível em breve.")}
                                            onClick={() => setConfirmStep(k)} disabled={busy !== null} data-testid={`guide-apply-${k}`}>Aplicar no PingWin</ReasonButton>
                                    )}
                                    {s.error && <span className="text-danger fs-12 w-100">{s.error}</span>}
                                </li>
                            ))}
                        </ul>
                    )}
                    {write && !FINAL.includes(write.status) && <div className="fs-13"><Spinner size="sm" className="me-1" />A gravar no PingWin e a reler…</div>}
                    {!view.apply.can_apply && view.apply.manual.length > 0 && (
                        <div data-testid="guide-manual">
                            <div className="fs-13 fw-semibold mb-1">{view.apply.proven ? "Os passos à mão" : "Disponível em breve. Até lá, os passos à mão no PingWin:"}</div>
                            <ol className="fs-13 ps-3 mb-0">{view.apply.manual.map((m, k) => <li key={k}>{m}</li>)}</ol>
                        </div>
                    )}
                </Step>
            )}

            {decided && (view.recipes_conversion.items.length > 0 || view.recipes_conversion.needs_base_change) && (
                <Step n={7} title="Converter as fichas para a unidade nova" testid="guide-recipes">
                    {view.recipes_conversion.needs_base_change ? (
                        <p className="fs-13 mb-2">Primeiro mude a unidade base no PingWin (ver o aviso). Depois, marque aqui que já mudou e converta as fichas uma a uma.</p>
                    ) : null}
                    {view.recipes_conversion.needs_base_change && (
                        <Button size="sm" color="light" className="mb-2" onClick={() => setAnswers({ ...answers, base_mudada: "sim" })}>Já mudei a unidade base no PingWin</Button>
                    )}
                    <ul className="list-unstyled mb-0">
                        {view.recipes_conversion.items.map((r) => (
                            <li key={r.product_id} className="d-flex flex-wrap align-items-center gap-2 py-1 border-bottom border-light-subtle">
                                <span className="flex-grow-1">{r.label}: {r.before} → <strong>{r.after}</strong></span>
                                <ReasonButton type="button" size="sm" color="outline-primary" reason={editReason ?? (view.recipes_conversion.ready ? null : "Mude primeiro a unidade base no PingWin.")}
                                    onClick={() => setPrefill({ productId: r.product_id, bomId: r.bom_id, prefill: { note: `Conversão para a unidade nova: ${r.before} → ${r.after}. Reveja e grave.`,
                                        lines: [{ replace_component_id: r.component_id, component_id: r.component_id, description: view.article.description, unit_id: r.unit_id, qnt: r.qnt }] } })}>
                                    Abrir no editor da ficha
                                </ReasonButton>
                            </li>
                        ))}
                    </ul>
                </Step>
            )}

            {view.history.length > 0 && (
                <details className="mb-3 fs-13"><summary className="text-primary" style={{ cursor: "pointer" }}>Histórico das decisões</summary>
                    <ul className="list-unstyled mt-2 mb-0">{view.history.map((h, k) => (
                        <li key={k} className="py-1 border-bottom border-light-subtle"><span className="text-muted me-2">{h.at ? new Date(h.at).toLocaleString("pt-PT") : ""}</span>
                            {h.action} · {h.user ?? "XPLENDOR"}{h.reason ? `: ${h.reason}` : ""}</li>
                    ))}</ul>
                </details>
            )}

            <Modal isOpen={naOpen} toggle={() => setNaOpen(false)} centered>
                <ModalHeader toggle={() => setNaOpen(false)}>Não se aplica</ModalHeader>
                <ModalBody>
                    <Label for="guide-na" className="form-label">Porque não se aplica a este artigo?</Label>
                    <Input id="guide-na" type="textarea" rows={2} value={naReason} onChange={(e) => setNaReason(e.target.value)} placeholder="Ex.: é um artigo de oferta, não entra no stock." />
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setNaOpen(false)}>Cancelar</Button>
                    <Button color="secondary" onClick={notApplicable} disabled={naReason.trim().length < 3 || busy !== null}>Guardar</Button>
                </ModalFooter>
            </Modal>
            <Modal isOpen={confirmStep !== null} toggle={() => setConfirmStep(null)} centered>
                <ModalHeader toggle={() => setConfirmStep(null)}>Gravar no PingWin</ModalHeader>
                <ModalBody>
                    <p className="mb-1">Vai gravar no PingWin: <strong>{confirmStep !== null ? view.apply.steps[confirmStep]?.label : ""}</strong>.</p>
                    <p className="text-muted fs-13 mb-0">Uma alteração só, no artigo {view.article.label}. A XPLENDOR relê o artigo no fim para confirmar.</p>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setConfirmStep(null)}>Cancelar</Button>
                    <Button color="primary" onClick={() => confirmStep !== null && apply(confirmStep)} data-testid="guide-apply-confirm">Gravar no PingWin</Button>
                </ModalFooter>
            </Modal>
            {prefill && (
                <RecipeEditorModal companyId={companyId} productId={prefill.productId} bomId={prefill.bomId} hidden={hidden} prefill={prefill.prefill}
                    onSaved={() => onChanged()} onClose={() => setPrefill(null)} />
            )}
        </div>
    );
}
