import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Badge, Button, Card, CardBody, CardHeader, Container, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner, Table } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import ReasonButton from "Components/Common/ReasonButton";
import XSelect from "pages/Editorial/XSelect";
import {
    applyAiModelToAll, chooseAiBlindCase, createAiBlindTest, getAiBlindTest, getAiBlindTests, getAiModels, updateAiModel,
} from "helpers/laravel_helper";

/**
 * Administração › Modelos de IA (só o root): o fornecedor, o modelo e o nível de raciocínio de
 * cada função, "Aplicar a todas as funções", o histórico, os custos do mês e o teste às cegas
 * entre dois modelos. O OCR das faturas não aparece aqui: continua como está.
 */

type Fn = { key: string; label: string; hint: string | null; provider: string; model: string; effort: string };
type Model = { key: string; label: string; provider: string; efforts: string[]; prices: { input: number; cached_input: number; output: number } };
type Provider = { key: string; label: string; configured: boolean };
type HistoryRow = { function: string; from: string | null; to: string; applied_to_all: boolean; user: string | null; at: string };
type Usage = { function: string; requests: number; provider_errors: number; input_tokens: number; output_tokens: number; reasoning_tokens: number; cost_usd: number };
type Overview = { functions: Fn[]; models: Model[]; providers: Provider[]; history: HistoryRow[]; usage: Usage[] };

type Side = { text: string | null; error: string | null; ready: boolean; pt_issues: string[] };
type BlindCase = { id: number; index: number; input: Record<string, string | number>; left: Side; right: Side; choice: "left" | "right" | "tie" | null };
type BlindTest = { id: number; function: string; function_label: string; status: string; cases: BlindCase[] };
type BlindRow = { id: number; function: string; function_label: string; status: string; total: number; chosen: number; complete: boolean; models: string[] | null; created_at: string };
type ReportSide = { model: string; wins: number; pt_failures: number; errors: number; avg_cost_usd: number; total_cost_usd: number; avg_ms: number };
type ReportRow = { function: string; function_label: string; cases: number; ties: number; a: ReportSide; b: ReportSide };

const EFFORT_LABEL: Record<string, string> = { low: "Baixo", medium: "Médio", high: "Alto" };
const usd = (v: number, digits = 4) => `${v.toLocaleString("pt-PT", { minimumFractionDigits: digits, maximumFractionDigits: digits })} USD`;
const dmyHm = (iso: string) => new Date(iso.replace(" ", "T")).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" });
const errorOf = (e: any, fallback: string): string => {
    const body = e?.response?.data ?? e;
    const first = body?.errors ? (Object.values(body.errors).flat()[0] as string) : null;
    return first || body?.message || fallback;
};

export default function AiModelsPage() {
    document.title = "Modelos de IA | Xplendor";
    const [view, setView] = useState<"functions" | "blind">("functions");
    const [data, setData] = useState<Overview | null>(null);
    const [saving, setSaving] = useState<string | null>(null);
    const [applyOpen, setApplyOpen] = useState(false);
    const [newTestOpen, setNewTestOpen] = useState(false);

    const load = useCallback(() => {
        getAiModels().then((r: any) => setData(r?.data ?? null)).catch(() => toast.error("Não foi possível carregar os modelos."));
    }, []);
    useEffect(() => { load(); }, [load]);

    const models = useMemo(() => data?.models ?? [], [data]);
    const providerLabel = (key: string) => data?.providers.find((p) => p.key === key)?.label ?? key;
    const modelOptions = models.map((m) => ({ value: m.key, label: `${m.label} (${providerLabel(m.provider)})` }));
    const effortOptions = (model: string) => (models.find((m) => m.key === model)?.efforts ?? []).map((e) => ({ value: e, label: EFFORT_LABEL[e] ?? e }));
    const functionLabel = (key: string) => data?.functions.find((f) => f.key === key)?.label ?? key;
    const missingKey = (model: string) => {
        const p = data?.providers.find((x) => x.key === models.find((m) => m.key === model)?.provider);
        return p && !p.configured ? p : null;
    };

    const save = async (fn: Fn, model: string, effort: string) => {
        const allowed = models.find((m) => m.key === model)?.efforts ?? [];
        const next = allowed.includes(effort) ? effort : allowed[0];
        setSaving(fn.key);
        try {
            const r: any = await updateAiModel(fn.key, { model, effort: next });
            setData(r?.data ?? null);
            toast.success(`${fn.label}: ${models.find((m) => m.key === model)?.label}, raciocínio ${(EFFORT_LABEL[next] ?? next).toLowerCase()}.`);
        } catch (e: any) { toast.error(errorOf(e, "Não foi possível guardar o modelo.")); }
        finally { setSaving(null); }
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Modelos de IA" breadcrumbs={[{ label: "Administração", to: "/admin" }]}
                    description="O fornecedor, o modelo e o nível de raciocínio de cada função de IA. As chaves ficam só no servidor. O OCR das faturas não está aqui e continua como está."
                    actions={view === "functions"
                        ? <ReasonButton color="primary" onClick={() => setApplyOpen(true)} reason={!data ? "A carregar." : null}><i className="ri-stack-line me-1" />Aplicar a todas as funções</ReasonButton>
                        : <Button color="primary" onClick={() => setNewTestOpen(true)}><i className="ri-add-line me-1" />Novo teste</Button>} />

                <div className="xp-seg mb-3" role="tablist" aria-label="Vista">
                    <button type="button" role="tab" aria-selected={view === "functions"} className={view === "functions" ? "on" : ""} onClick={() => setView("functions")}>Funções</button>
                    <button type="button" role="tab" aria-selected={view === "blind"} className={view === "blind" ? "on" : ""} onClick={() => setView("blind")}>Teste às cegas</button>
                </div>

                {!data ? <div className="text-center py-5"><Spinner color="primary" /></div> : view === "functions" ? (
                    <>
                        {data.providers.filter((p) => !p.configured).map((p) => (
                            <div key={p.key} className="alert alert-warning fs-13" role="alert">
                                <i className="ri-key-2-line me-1" />A chave da {p.label} não está configurada no servidor. As funções com um modelo da {p.label} vão falhar até ser configurada.
                            </div>
                        ))}
                        <Card>
                            <CardHeader><h5 className="card-title mb-0">Funções</h5></CardHeader>
                            <CardBody>
                                <div className="table-responsive">
                                    <Table className="align-middle mb-0 fs-13">
                                        <thead className="text-muted table-light">
                                            <tr><th>Função</th><th style={{ minWidth: 200 }}>Modelo<span className="d-md-none"> e raciocínio</span></th><th className="d-none d-md-table-cell" style={{ minWidth: 130 }}>Raciocínio</th><th className="text-end d-none d-md-table-cell">Pedidos este mês</th><th className="text-end d-none d-md-table-cell">Custo este mês</th></tr>
                                        </thead>
                                        <tbody>
                                            {data.functions.map((f) => {
                                                const u = data.usage.find((x) => x.function === f.key);
                                                const missing = missingKey(f.model);
                                                return (
                                                    <tr key={f.key} data-testid={`ai-fn-${f.key}`}>
                                                        <td>
                                                            <div className="fw-semibold">{f.label}</div>
                                                            {f.hint && <div className="text-muted fs-12">{f.hint}</div>}
                                                            <div className="text-muted fs-12 d-md-none mt-1">Este mês: {u ? u.requests : 0} pedidos, {usd(u?.cost_usd ?? 0)}</div>
                                                        </td>
                                                        <td>
                                                            <XSelect small ariaLabel={`Modelo: ${f.label}`} options={modelOptions} value={f.model} disabled={saving === f.key} onChange={(m) => m !== f.model && save(f, m, f.effort)} />
                                                            {missing && <div className="text-warning fs-12 mt-1">Sem a chave da {missing.label}.</div>}
                                                            <div className="d-md-none mt-2">
                                                                <XSelect small ariaLabel={`Raciocínio: ${f.label}`} options={effortOptions(f.model)} value={f.effort} disabled={saving === f.key} onChange={(e) => e !== f.effort && save(f, f.model, e)} />
                                                            </div>
                                                        </td>
                                                        <td className="d-none d-md-table-cell">
                                                            <XSelect small ariaLabel={`Raciocínio: ${f.label}`} options={effortOptions(f.model)} value={f.effort} disabled={saving === f.key} onChange={(e) => e !== f.effort && save(f, f.model, e)} />
                                                        </td>
                                                        <td className="text-end d-none d-md-table-cell">
                                                            {u ? u.requests : 0}
                                                            {u && u.provider_errors > 0 && <div className="text-muted fs-12">{u.provider_errors} com erro do fornecedor</div>}
                                                        </td>
                                                        <td className="text-end text-nowrap d-none d-md-table-cell">{usd(u?.cost_usd ?? 0)}</td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </Table>
                                </div>
                                <p className="text-muted fs-12 mt-3 mb-0">
                                    A troca vale a partir do pedido seguinte de cada função. O raciocínio está sempre ligado: o nível define quanto o modelo pensa antes de responder (mais raciocínio, mais custo e mais tempo).
                                </p>
                            </CardBody>
                        </Card>

                        <Card>
                            <CardHeader><h5 className="card-title mb-0">Preços</h5></CardHeader>
                            <CardBody>
                                <div className="table-responsive">
                                    <Table className="align-middle mb-0 fs-13">
                                        <thead className="text-muted table-light">
                                            <tr><th>Modelo</th><th>Fornecedor</th><th className="text-end">Entrada</th><th className="text-end">Entrada em cache</th><th className="text-end">Saída e raciocínio</th></tr>
                                        </thead>
                                        <tbody>
                                            {models.map((m) => (
                                                <tr key={m.key}>
                                                    <td className="fw-semibold">{m.label}</td><td>{providerLabel(m.provider)}</td>
                                                    <td className="text-end">{usd(m.prices.input, 2)}</td><td className="text-end">{usd(m.prices.cached_input, 2)}</td><td className="text-end">{usd(m.prices.output, 2)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </Table>
                                </div>
                                <p className="text-muted fs-12 mt-3 mb-0">Por milhão de tokens. O custo de cada pedido é calculado com estes preços e fica registado.</p>
                            </CardBody>
                        </Card>

                        <Card>
                            <CardHeader><h5 className="card-title mb-0">Histórico de alterações</h5></CardHeader>
                            <CardBody>
                                {data.history.length === 0 ? <p className="text-muted mb-0">Ainda não houve alterações: todas as funções usam os valores iniciais.</p> : (
                                    <div className="table-responsive">
                                        <Table className="align-middle mb-0 fs-13">
                                            <thead className="text-muted table-light"><tr><th>Data</th><th>Função</th><th>Antes</th><th>Depois</th><th>Por</th></tr></thead>
                                            <tbody>
                                                {data.history.map((h, i) => (
                                                    <tr key={i}>
                                                        <td className="text-nowrap">{dmyHm(h.at)}</td>
                                                        <td>{functionLabel(h.function)}{h.applied_to_all && <Badge color="light" className="text-body fw-normal ms-2">Todas as funções</Badge>}</td>
                                                        <td className="text-muted">{h.from ?? "Valor inicial"}</td><td>{h.to}</td><td>{h.user ?? ""}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </Table>
                                    </div>
                                )}
                            </CardBody>
                        </Card>
                    </>
                ) : (
                    <BlindTests functionLabel={functionLabel} />
                )}
            </Container>

            {data && <ApplyAllModal isOpen={applyOpen} onClose={() => setApplyOpen(false)} models={models} modelOptions={modelOptions} effortOptions={effortOptions}
                count={data.functions.length} missingKey={missingKey} onApplied={(d) => { setData(d); setApplyOpen(false); }} />}
            {data && <NewBlindTestModal isOpen={newTestOpen} onClose={() => setNewTestOpen(false)} functions={data.functions} onCreated={() => { setNewTestOpen(false); setView("blind"); }} />}
        </div>
    );
}

function ApplyAllModal({ isOpen, onClose, models, modelOptions, effortOptions, count, missingKey, onApplied }: {
    isOpen: boolean; onClose: () => void; models: Model[]; modelOptions: { value: string; label: string }[];
    effortOptions: (m: string) => { value: string; label: string }[]; count: number; missingKey: (m: string) => Provider | null; onApplied: (d: Overview) => void;
}) {
    const [model, setModel] = useState("");
    const [effort, setEffort] = useState("");
    const [busy, setBusy] = useState(false);
    useEffect(() => { if (isOpen) { setModel(""); setEffort(""); } }, [isOpen]);

    const apply = async () => {
        setBusy(true);
        try {
            const r: any = await applyAiModelToAll({ model, effort });
            toast.success("Modelo aplicado a todas as funções.");
            onApplied(r?.data);
        } catch (e: any) { toast.error(errorOf(e, "Não foi possível aplicar o modelo.")); }
        finally { setBusy(false); }
    };
    const label = models.find((m) => m.key === model)?.label;
    const missing = model ? missingKey(model) : null;

    return (
        <Modal isOpen={isOpen} toggle={() => !busy && onClose()} centered>
            <ModalHeader toggle={() => !busy && onClose()}>Aplicar a todas as funções</ModalHeader>
            <ModalBody>
                <Label for="apply-all-model" className="mb-1">Modelo</Label>
                <XSelect id="apply-all-model" options={modelOptions} value={model || null} onChange={(m) => { setModel(m); setEffort(""); }} />
                <Label for="apply-all-effort" className="mb-1 mt-3">Raciocínio</Label>
                <XSelect id="apply-all-effort" options={model ? effortOptions(model) : []} value={effort || null} onChange={setEffort} disabled={!model} />
                {model && effort && (
                    <div className="alert alert-warning fs-13 mt-3 mb-0" role="alert">
                        As {count} funções passam a usar o {label}, com raciocínio {(EFFORT_LABEL[effort] ?? effort).toLowerCase()}, a partir do pedido seguinte. Fica registado no histórico.
                        {missing && <> A chave da {missing.label} não está configurada: os pedidos vão falhar até ser configurada.</>}
                    </div>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" disabled={busy} onClick={onClose}>Cancelar</Button>
                <ReasonButton color="primary" disabled={busy} onClick={apply} reason={!model ? "Escolha o modelo." : !effort ? "Escolha o nível de raciocínio." : null}>
                    {busy ? <Spinner size="sm" /> : "Aplicar a todas"}
                </ReasonButton>
            </ModalFooter>
        </Modal>
    );
}

function NewBlindTestModal({ isOpen, onClose, functions, onCreated }: { isOpen: boolean; onClose: () => void; functions: Fn[]; onCreated: () => void }) {
    const [fn, setFn] = useState("");
    const [busy, setBusy] = useState(false);
    useEffect(() => { if (isOpen) setFn(""); }, [isOpen]);

    const create = async () => {
        setBusy(true);
        try {
            await createAiBlindTest(fn);
            toast.success("Teste criado. Os casos estão a ser gerados.");
            onCreated();
        } catch (e: any) { toast.error(errorOf(e, "Não foi possível criar o teste.")); }
        finally { setBusy(false); }
    };

    return (
        <Modal isOpen={isOpen} toggle={() => !busy && onClose()} centered>
            <ModalHeader toggle={() => !busy && onClose()}>Novo teste às cegas</ModalHeader>
            <ModalBody>
                <p className="fs-13">São gerados 10 casos fixos da função com o Claude Opus 5.5 e com o GPT-6.1 Sol, com o nível de raciocínio inicial da função. Cada par aparece sem o nome do modelo e com os lados sorteados. Os casos custam como pedidos normais.</p>
                <Label for="blind-fn" className="mb-1">Função</Label>
                <XSelect id="blind-fn" options={functions.map((f) => ({ value: f.key, label: f.label }))} value={fn || null} onChange={setFn} />
            </ModalBody>
            <ModalFooter>
                <Button color="light" disabled={busy} onClick={onClose}>Cancelar</Button>
                <ReasonButton color="primary" disabled={busy} onClick={create} reason={!fn ? "Escolha a função." : null}>{busy ? <Spinner size="sm" /> : "Gerar 10 casos"}</ReasonButton>
            </ModalFooter>
        </Modal>
    );
}

function BlindTests({ functionLabel }: { functionLabel: (k: string) => string }) {
    const [tests, setTests] = useState<BlindRow[] | null>(null);
    const [report, setReport] = useState<ReportRow[]>([]);
    const [open, setOpen] = useState<number | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const load = useCallback(() => {
        getAiBlindTests().then((r: any) => { setTests(r?.data?.tests ?? []); setReport(r?.data?.report ?? []); }).catch(() => setTests([]));
    }, []);
    useEffect(() => { load(); }, [load]);
    // Enquanto há testes a gerar, atualiza a lista.
    useEffect(() => {
        if (timer.current) clearTimeout(timer.current);
        if (tests?.some((t) => t.status === "generating")) timer.current = setTimeout(load, 5000);
        return () => { if (timer.current) clearTimeout(timer.current); };
    }, [tests, load]);

    return (
        <>
            <Card>
                <CardHeader><h5 className="card-title mb-0">Testes</h5></CardHeader>
                <CardBody>
                    {tests === null ? <div className="text-center py-3"><Spinner size="sm" /></div> : tests.length === 0
                        ? <p className="text-muted mb-0">Ainda não há testes. Use "Novo teste" para gerar 10 casos de uma função.</p>
                        : (
                            <div className="table-responsive">
                                <Table className="align-middle mb-0 fs-13">
                                    <thead className="text-muted table-light"><tr><th>Função</th><th>Criado</th><th>Estado</th><th>Avaliados</th><th>Modelos</th><th /></tr></thead>
                                    <tbody>
                                        {tests.map((t) => (
                                            <tr key={t.id}>
                                                <td className="fw-semibold">{t.function_label}</td>
                                                <td className="text-nowrap">{dmyHm(t.created_at)}</td>
                                                <td>{t.status === "generating" ? <span className="text-muted"><Spinner size="sm" className="me-1" />A gerar</span> : <Badge color="success-subtle" className="text-success fw-normal">Pronto</Badge>}</td>
                                                <td>{t.chosen}/{t.total}</td>
                                                <td>{t.models ? t.models.join(" e ") : <span className="text-muted">Revelados no fim</span>}</td>
                                                <td className="text-end">
                                                    <Button size="sm" color="outline-primary" onClick={() => setOpen(t.id)}>{t.complete ? "Ver" : "Avaliar"}</Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </Table>
                            </div>
                        )}
                </CardBody>
            </Card>

            <Card>
                <CardHeader><h5 className="card-title mb-0">Relatório por função</h5></CardHeader>
                <CardBody>
                    {report.length === 0 ? <p className="text-muted mb-0">O relatório aparece quando um teste estiver avaliado por inteiro (os modelos só são revelados nessa altura).</p> : (
                        <div className="table-responsive">
                            <Table className="align-middle mb-0 fs-13" data-testid="blind-report">
                                <thead className="text-muted table-light">
                                    <tr><th>Função</th><th>Modelo</th><th className="text-end">Preferido</th><th className="text-end">Falhas no português</th><th className="text-end">Erros</th><th className="text-end">Custo médio</th><th className="text-end">Tempo médio</th></tr>
                                </thead>
                                <tbody>
                                    {report.flatMap((r) => [r.a, r.b].map((s, i) => (
                                        <tr key={`${r.function}-${s.model}`}>
                                            {i === 0 && <td rowSpan={2} className="fw-semibold">{r.function_label || functionLabel(r.function)}<div className="text-muted fs-12 fw-normal">{r.cases} casos, {r.ties} empates</div></td>}
                                            <td>{s.model}</td>
                                            <td className="text-end">{s.wins} de {r.cases}</td>
                                            <td className="text-end">{s.pt_failures}</td>
                                            <td className="text-end">{s.errors}</td>
                                            <td className="text-end text-nowrap">{usd(s.avg_cost_usd)}</td>
                                            <td className="text-end text-nowrap">{(s.avg_ms / 1000).toLocaleString("pt-PT", { maximumFractionDigits: 1 })} s</td>
                                        </tr>
                                    )))}
                                </tbody>
                            </Table>
                        </div>
                    )}
                </CardBody>
            </Card>

            {open !== null && <BlindTestModal testId={open} onClose={() => { setOpen(null); load(); }} />}
        </>
    );
}

/** Uma resposta legível: JSON como lista de campos; HTML do blog como texto com parágrafos. */
function Answer({ side }: { side: Side }) {
    if (!side.ready) return <div className="text-muted py-3"><Spinner size="sm" className="me-1" />A gerar</div>;
    if (side.error) return <div className="text-danger fs-13">Erro: {side.error}</div>;
    let parsed: unknown = null;
    try { parsed = JSON.parse(side.text ?? ""); } catch { parsed = null; }
    const plain = (s: string) => s.replace(/<\/(p|h2|h3|li)>/g, "\n").replace(/<li>/g, "• ").replace(/<[^>]+>/g, "").trim();
    const render = (v: unknown, depth = 0): React.ReactNode => {
        if (v === null || v === undefined || v === "") return <span className="text-muted">vazio</span>;
        if (typeof v !== "object") return <span style={{ whiteSpace: "pre-wrap" }}>{plain(String(v))}</span>;
        if (Array.isArray(v)) return <ul className="ps-3 mb-0">{v.map((x, i) => <li key={i}>{render(x, depth + 1)}</li>)}</ul>;
        return (
            <dl className="mb-0">
                {Object.entries(v as Record<string, unknown>).map(([k, x]) => (
                    <div key={k} className={depth === 0 ? "mb-2" : "mb-1"}><dt className="fs-12 text-muted fw-semibold">{k}</dt><dd className="mb-0">{render(x, depth + 1)}</dd></div>
                ))}
            </dl>
        );
    };
    return <div className="fs-13">{parsed !== null && typeof parsed === "object" ? render(parsed) : <span style={{ whiteSpace: "pre-wrap" }}>{plain(side.text ?? "")}</span>}</div>;
}

function BlindTestModal({ testId, onClose }: { testId: number; onClose: () => void }) {
    const [test, setTest] = useState<BlindTest | null>(null);
    const [index, setIndex] = useState(0);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => getAiBlindTest(testId).then((r: any) => setTest(r?.data ?? null)).catch(() => toast.error("Não foi possível carregar o teste.")), [testId]);
    useEffect(() => { load(); }, [load]);
    useEffect(() => {
        if (test?.status !== "generating") return;
        const t = setTimeout(load, 5000);
        return () => clearTimeout(t);
    }, [test, load]);

    const c = test?.cases[index];
    const ready = !!c && c.left.ready && c.right.ready;
    const choose = async (choice: "left" | "right" | "tie") => {
        if (!c) return;
        setBusy(true);
        try {
            const r: any = await chooseAiBlindCase(testId, c.id, choice);
            setTest(r?.data ?? test);
            if (index < (test?.cases.length ?? 1) - 1) setIndex(index + 1);
        } catch (e: any) { toast.error(errorOf(e, "Não foi possível registar a escolha.")); }
        finally { setBusy(false); }
    };
    const pick = (choice: "left" | "right" | "tie", label: string) => (
        <ReasonButton color={c?.choice === choice ? "primary" : "outline-primary"} disabled={busy} onClick={() => choose(choice)} reason={!ready ? "As duas respostas ainda estão a ser geradas." : null}>{label}</ReasonButton>
    );

    return (
        <Modal isOpen toggle={onClose} size="xl" scrollable centered data-testid="blind-test-modal">
            <ModalHeader toggle={onClose}>Teste às cegas: {test?.function_label ?? ""}</ModalHeader>
            <ModalBody>
                {!test || !c ? <div className="text-center py-4"><Spinner color="primary" /></div> : (
                    <>
                        <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
                            <strong>Caso {c.index} de {test.cases.length}</strong>
                            <span className="text-muted fs-13">{Object.entries(c.input).map(([k, v]) => `${k}: ${v}`).join("; ")}</span>
                            <span className="ms-auto text-muted fs-12">{test.cases.filter((x) => x.choice).length} avaliados</span>
                        </div>
                        <div className="row g-3">
                            {(["left", "right"] as const).map((s, i) => (
                                <div className="col-12 col-lg-6" key={s}>
                                    <div className={`border rounded p-3 h-100 ${c.choice === s ? "border-primary" : ""}`}>
                                        <h6 className="mb-2">Resposta {i + 1}</h6>
                                        <Answer side={c[s]} />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </>
                )}
            </ModalBody>
            <ModalFooter className="justify-content-between">
                <div className="d-flex gap-2">
                    <Button color="light" disabled={index === 0} onClick={() => setIndex(index - 1)} aria-label="Caso anterior"><i className="ri-arrow-left-s-line" /></Button>
                    <Button color="light" disabled={!test || index >= test.cases.length - 1} onClick={() => setIndex(index + 1)} aria-label="Caso seguinte"><i className="ri-arrow-right-s-line" /></Button>
                </div>
                <div className="d-flex flex-wrap gap-2">
                    {pick("left", "Prefiro a 1")}
                    {pick("tie", "Empate")}
                    {pick("right", "Prefiro a 2")}
                </div>
            </ModalFooter>
        </Modal>
    );
}
