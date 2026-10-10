import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Badge, Button, Container, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner, Table } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import ReasonButton from "Components/Common/ReasonButton";
import XSelect from "Components/Common/Select";
import {
    applyAiModelToAll, chooseAiBlindCase, createAiBlindTest, getAiBlindTest, getAiBlindTests, getAiModels, updateAiModel,
} from "helpers/laravel_helper";

/**
 * Administração › Modelos de IA (só o root): o fornecedor, o modelo e o nível de raciocínio de
 * cada função, "Aplicar a todas as funções", o histórico, os custos do mês e o teste às cegas
 * entre dois modelos. O OCR das faturas aparece como duas funções (PDF com texto; digitalizadas e
 * fotografias); sem escolha, vale a reserva do .env. Não entra no "Aplicar a todas" nem no teste às cegas.
 */

/** group "ocr": as funções do OCR das faturas; reserve: sem escolha, vale o modelo do .env (reserve_env). */
type Fn = { key: string; label: string; hint: string | null; provider: string; model: string; effort: string; group?: string | null; reserve?: boolean; reserve_env?: string | null };
type Model = { key: string; label: string; provider: string; efforts: string[]; prices: { input: number; cached_input: number; output: number } | null };
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

const EFFORT_LABEL: Record<string, string> = { default: "O do modelo", low: "Baixo", medium: "Médio", high: "Alto" };
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

    const viewToggle = (
        <div className="xp-seg" role="tablist" aria-label="Vista">
            <button type="button" role="tab" aria-selected={view === "functions"} className={view === "functions" ? "on" : ""} onClick={() => setView("functions")}>Funções</button>
            <button type="button" role="tab" aria-selected={view === "blind"} className={view === "blind" ? "on" : ""} onClick={() => setView("blind")}>Teste às cegas</button>
        </div>
    );

    const fnCols = useDataColumns<Fn>("administracao.ia.funcoes", [
        {
            id: "fn", header: "Função", value: (f) => f.label, hideable: false, mobile: "title",
            cell: (f) => (
                <div data-testid={`ai-fn-${f.key}`}>
                    <div className="fw-semibold">{f.label}</div>
                    {f.hint && <div className="text-muted fs-12">{f.hint}</div>}
                </div>
            ),
        },
        {
            id: "model", header: "Modelo", value: (f) => f.model, hideable: false, className: "", sortable: false,
            cell: (f) => {
                const missing = missingKey(f.model);
                return (
                    <div style={{ minWidth: 200 }}>
                        <XSelect small ariaLabel={`Modelo: ${f.label}`} value={f.model} disabled={saving === f.key} onChange={(m) => m !== f.model && save(f, m, f.effort)}
                            options={f.reserve && f.model && !models.some((m) => m.key === f.model) ? [...modelOptions, { value: f.model, label: `${f.model} (reserva do .env)` }] : modelOptions} />
                        {f.reserve && <div className="text-muted fs-12 mt-1" data-testid={`ai-fn-reserve-${f.key}`}>{f.model ? `Reserva do .env (${f.reserve_env}): ${f.model}.` : `Sem modelo: escolha um aqui ou defina ${f.reserve_env} no .env.`}</div>}
                        {missing && <div className="text-warning fs-12 mt-1">Sem a chave da {missing.label}.</div>}
                    </div>
                );
            },
        },
        {
            id: "effort", header: "Raciocínio", value: (f) => f.effort, sortable: false,
            cell: (f) => <div style={{ minWidth: 130 }}><XSelect small ariaLabel={`Raciocínio: ${f.label}`} options={effortOptions(f.model)} value={f.effort} disabled={saving === f.key} onChange={(e) => e !== f.effort && save(f, f.model, e)} /></div>,
        },
        {
            id: "requests", header: "Pedidos este mês", align: "end",
            value: (f) => data?.usage.find((x) => x.function === f.key)?.requests ?? 0,
            cell: (f) => { const u = data?.usage.find((x) => x.function === f.key); return <>{u ? u.requests : 0}{u && u.provider_errors > 0 && <div className="text-muted fs-12">{u.provider_errors} com erro do fornecedor</div>}</>; },
        },
        { id: "cost", header: "Custo este mês", align: "end", nowrap: true, value: (f) => data?.usage.find((x) => x.function === f.key)?.cost_usd ?? 0, cell: (f) => usd(data?.usage.find((x) => x.function === f.key)?.cost_usd ?? 0) },
    ] as DTColumn<Fn>[]);

    const priceCols = useDataColumns<Model>("administracao.ia.precos", [
        { id: "model", header: "Modelo", value: (m) => m.label, hideable: false, mobile: "title", cell: (m) => <span className="fw-semibold">{m.label}</span> },
        { id: "provider", header: "Fornecedor", value: (m) => providerLabel(m.provider) },
        { id: "input", header: "Entrada", align: "end", value: (m) => m.prices?.input, cell: (m) => (m.prices ? usd(m.prices.input, 2) : <span className="text-warning">Preço por confirmar (custo desconhecido)</span>) },
        { id: "cached", header: "Entrada em cache", align: "end", value: (m) => m.prices?.cached_input, cell: (m) => (m.prices ? usd(m.prices.cached_input, 2) : "—") },
        { id: "output", header: "Saída e raciocínio", align: "end", value: (m) => m.prices?.output, cell: (m) => (m.prices ? usd(m.prices.output, 2) : "—") },
    ] as DTColumn<Model>[]);

    const historyCols = useDataColumns<HistoryRow>("administracao.ia.historico", [
        { id: "at", header: "Data", value: (h) => h.at, cell: (h) => dmyHm(h.at), nowrap: true },
        { id: "fn", header: "Função", value: (h) => functionLabel(h.function), mobile: "title", cell: (h) => <>{functionLabel(h.function)}{h.applied_to_all && <Badge color="light" className="text-body fw-normal ms-2">Todas as funções</Badge>}</> },
        { id: "from", header: "Antes", value: (h) => h.from ?? "", cell: (h) => <span className="text-muted">{h.from ?? "Valor inicial"}</span> },
        { id: "to", header: "Depois", value: (h) => h.to },
        { id: "user", header: "Por", value: (h) => h.user ?? "" },
    ] as DTColumn<HistoryRow>[]);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Modelos de IA" breadcrumbs={[{ label: "Administração", to: "/admin" }]}
                    info="O fornecedor, o modelo e o nível de raciocínio de cada função de IA. As chaves ficam só no servidor. O OCR das faturas escolhe-se aqui à parte (sem escolha, vale o modelo do .env)." />

                {!data ? <div className="text-center py-5"><Spinner color="primary" /></div> : view === "functions" ? (
                    <>
                        {data.providers.filter((p) => !p.configured).map((p) => (
                            <div key={p.key} className="alert alert-warning fs-13" role="alert">
                                <i className="ri-key-2-line me-1" />A chave da {p.label} não está configurada no servidor. As funções com um modelo da {p.label} vão falhar até ser configurada.
                            </div>
                        ))}
                        {/* A vista (Funções / Teste às cegas) e a ação principal no cabeçalho do primeiro cartão. */}
                        <PageCard
                            title="Funções"
                            info="A troca vale a partir do pedido seguinte de cada função. O raciocínio está sempre ligado: o nível define quanto o modelo pensa antes de responder (mais raciocínio, mais custo e mais tempo)."
                            actions={<>
                                {viewToggle}
                                {fnCols.selector}
                                <ReasonButton size="sm" color="primary" onClick={() => setApplyOpen(true)} reason={!data ? "A carregar." : null}><i className="ri-stack-line me-1" />Aplicar a todas as funções</ReasonButton>
                            </>}
                        >
                            <DataTable columns={fnCols} data={data.functions} rowKey={(f) => f.key} caption="Funções de IA" paginate={false} />
                        </PageCard>

                        <PageCard title="Preços" info="Por milhão de tokens. O custo de cada pedido é calculado com estes preços e fica registado." actions={priceCols.selector}>
                            <DataTable columns={priceCols} data={models} rowKey={(m) => m.key} caption="Preços dos modelos" />
                        </PageCard>

                        <PageCard title="Histórico de alterações" actions={historyCols.selector}>
                            <DataTable columns={historyCols} data={data.history} rowKey={(h) => `${h.at}-${h.function}`} caption="Histórico de alterações"
                                empty={{ message: "Ainda não houve alterações: todas as funções usam os valores iniciais." }} />
                        </PageCard>
                    </>
                ) : (
                    <BlindTests functionLabel={functionLabel} viewToggle={viewToggle} onNewTest={() => setNewTestOpen(true)} />
                )}
            </Container>

            {data && <ApplyAllModal isOpen={applyOpen} onClose={() => setApplyOpen(false)} models={models} modelOptions={modelOptions} effortOptions={effortOptions}
                count={data.functions.filter((f) => f.group !== "ocr").length} missingKey={missingKey} onApplied={(d) => { setData(d); setApplyOpen(false); }} />}
            {data && <NewBlindTestModal isOpen={newTestOpen} onClose={() => setNewTestOpen(false)} functions={data.functions.filter((f) => f.group !== "ocr")} onCreated={() => { setNewTestOpen(false); setView("blind"); }} />}
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
                        As {count} funções passam a usar o {label}, com raciocínio {(EFFORT_LABEL[effort] ?? effort).toLowerCase()}, a partir do pedido seguinte (o OCR das faturas escolhe-se à parte). Fica registado no histórico.
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

function BlindTests({ functionLabel, viewToggle, onNewTest }: { functionLabel: (k: string) => string; viewToggle: React.ReactNode; onNewTest: () => void }) {
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

    const testCols = useDataColumns<BlindRow>("administracao.ia.testes", [
        { id: "fn", header: "Função", value: (t) => t.function_label, hideable: false, mobile: "title", cell: (t) => <span className="fw-semibold">{t.function_label}</span> },
        { id: "created", header: "Criado", value: (t) => t.created_at, cell: (t) => dmyHm(t.created_at), nowrap: true },
        { id: "status", header: "Estado", value: (t) => t.status, cell: (t) => (t.status === "generating" ? <span className="text-muted"><Spinner size="sm" className="me-1" />A gerar</span> : <Badge color="success-subtle" className="text-success fw-normal">Pronto</Badge>) },
        { id: "chosen", header: "Avaliados", value: (t) => t.chosen, cell: (t) => `${t.chosen}/${t.total}` },
        { id: "models", header: "Modelos", value: (t) => (t.models ? t.models.join(" e ") : ""), cell: (t) => (t.models ? t.models.join(" e ") : <span className="text-muted">Revelados no fim</span>) },
    ] as DTColumn<BlindRow>[]);

    return (
        <>
            <PageCard title="Testes"
                actions={<>
                    {viewToggle}
                    {testCols.selector}
                    <Button size="sm" color="primary" onClick={onNewTest}><i className="ri-add-line me-1" />Novo teste</Button>
                </>}>
                <DataTable
                    columns={testCols}
                    data={tests ?? []}
                    rowKey={(t) => t.id}
                    loading={tests === null}
                    caption="Testes às cegas"
                    empty={{ message: <>Ainda não há testes. Use "Novo teste" para gerar 10 casos de uma função.</> }}
                    rowActions={(t) => <Button size="sm" color="outline-primary" onClick={() => setOpen(t.id)}>{t.complete ? "Ver" : "Avaliar"}</Button>}
                />
            </PageCard>

            {/* Relatório agrupado por função (duas linhas por função, com rowSpan): fica em tabela própria. */}
            <PageCard title="Relatório por função" flush={false}>
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
            </PageCard>

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
