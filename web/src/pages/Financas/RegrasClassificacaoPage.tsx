import { useCallback, useEffect, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { Alert, Button, Card, CardBody, Col, Container, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import Skeleton from "Components/Common/Skeleton";
import { createFinanceRule, deleteFinanceRule, getFinanceRules, updateFinanceRule } from "helpers/laravel_helper";
import { FinanceRule, FinanceRuleEvent } from "common/models/financeMonth.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

/**
 * XPLENDOR — Finanças › Configuração › Regras de classificação: "quando → grupo", por entidade, por
 * artigo, por família ou por tipo de documento. Têm prioridade sobre a família do PingWin (que fica
 * como está: nada se escreve no PingWin). Cada mudança fica no histórico.
 */
type Option = { value: string; label: string };
type Options = { groups: Option[]; entities: Option[]; doc_types: Option[]; families: Option[] };
const TYPES: { value: FinanceRule["match_type"]; label: string }[] = [
    { value: "artigo", label: "Artigo (código)" }, { value: "entidade", label: "Entidade" },
    { value: "tipo_documento", label: "Tipo de documento" }, { value: "familia", label: "Família do PingWin" },
];
const ACTION: Record<string, string> = { criar: "Criada", editar: "Mudada", ativar: "Ativada", desativar: "Desativada", apagar: "Apagada" };
const empty = { match_type: "" as FinanceRule["match_type"] | "", values: "", entity: "", group: "", label: "" };

export default function RegrasClassificacaoPage() {
    document.title = "Regras de classificação | Finanças | Xplendor";
    const companyId = useWorkingCompanyId();
    const [params] = useSearchParams();
    const [rules, setRules] = useState<FinanceRule[] | null>(null);
    const [events, setEvents] = useState<FinanceRuleEvent[]>([]);
    const [opts, setOpts] = useState<Options>({ groups: [], entities: [], doc_types: [], families: [] });
    const [form, setForm] = useState(empty);
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [deleting, setDeleting] = useState<FinanceRule | null>(null);

    const load = useCallback(async () => {
        if (!companyId) return;
        try {
            const r: any = await getFinanceRules(companyId);
            setRules(r?.data?.rules ?? []);
            setEvents(r?.data?.events ?? []);
            setOpts(r?.data?.options ?? opts);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar as regras.");
            setRules([]);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [companyId]);
    useEffect(() => { load(); }, [load]);

    // "Criar regra" a partir de Finanças › Mês (um tipo de documento novo, uma entidade por classificar).
    useEffect(() => {
        const tipo = params.get("tipo") as FinanceRule["match_type"] | null;
        if (!tipo) return;
        setForm({ ...empty, match_type: tipo, values: tipo === "entidade" ? "" : params.get("valor") ?? "", entity: tipo === "entidade" ? params.get("valor") ?? "" : "",
            label: params.get("rotulo") ? `${tipo === "tipo_documento" ? "Tipo de documento" : "Entidade"} ${params.get("rotulo")}` : "" });
        setOpen(true);
    }, [params]);

    const valuesList = () => (form.match_type === "entidade" ? [form.entity] : form.values.split(/[,;\n]/).map((v) => v.trim()).filter(Boolean));
    const reason = !form.match_type ? "Escolha quando se aplica a regra." : valuesList().length === 0 ? "Indique o valor." : !form.group ? "Escolha o grupo." : null;

    const submit = async () => {
        setBusy(true);
        try {
            const entityName = opts.entities.find((e) => e.value === form.entity)?.label ?? null;
            await createFinanceRule(companyId as number, {
                match_type: form.match_type, match_values: valuesList(), match_label: form.label || null, group: form.group,
                entity_pingwin_id: form.entity || null, entity_name: entityName,
            });
            toast.success("Regra criada.");
            setOpen(false);
            setForm(empty);
            load();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível criar a regra.");
        } finally {
            setBusy(false);
        }
    };
    const toggle = async (r: FinanceRule) => {
        try {
            const res: any = await updateFinanceRule(companyId as number, r.id, { active: !r.active });
            toast.success(res?.message ?? "Regra guardada.");
            load();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar.");
        }
    };
    const remove = async () => {
        if (!deleting) return;
        setBusy(true);
        try {
            await deleteFinanceRule(companyId as number, deleting.id);
            toast.success("Regra apagada.");
            setDeleting(null);
            load();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível apagar.");
        } finally {
            setBusy(false);
        }
    };
    const valueOptions = form.match_type === "tipo_documento" ? opts.doc_types : form.match_type === "familia" ? opts.families : null;

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader title="Regras de classificação" breadcrumbs={[{ label: "Finanças" }, { label: "Configuração" }]}
                    info="Quando um gasto cumpre a regra, entra no grupo escolhido, mesmo que a família no PingWin diga outra coisa. A família do PingWin fica como está e continua a ver-se ao lado." />
                <PageCard title="Regras" status={rules ? `${rules.filter((r) => r.active).length} ativas` : undefined} flush={false} data-testid="rules-card"
                    actions={<Button color="primary" onClick={() => { setForm(empty); setOpen(true); }} data-testid="rule-new"><i className="ri-add-line me-1" />Criar regra</Button>}>
                    <Alert color="info" className="py-2 fs-13">Ganha a regra mais específica: artigo, depois entidade, depois tipo de documento, depois família.</Alert>
                    {!rules ? <Skeleton variant="lines" lines={4} /> : rules.length === 0 ? <p className="text-muted mb-0">Ainda não há regras.</p> : (
                        <div className="table-responsive">
                            <Table size="sm" className="fs-13 align-middle mb-0" data-testid="rules-table">
                                <thead className="table-light text-muted"><tr><th>Regra</th><th>Quando</th><th>Estado</th><th /></tr></thead>
                                <tbody>
                                    {rules.map((r) => (
                                        <tr key={r.id} className={r.active ? undefined : "text-muted"} data-testid={`rule-${r.id}`}>
                                            <td className="fw-medium">{r.label}</td>
                                            <td>{TYPES.find((t) => t.value === r.match_type)?.label}: {r.match_values.join(", ")}{r.entity_name && r.match_type !== "entidade" && <> · só de {r.entity_name}</>}</td>
                                            <td>{r.active ? <span className="badge bg-success-subtle text-success">Ativa</span> : <span className="badge bg-secondary-subtle text-secondary">Desativada</span>}</td>
                                            <td className="text-end text-nowrap">
                                                <Button size="sm" color="light" onClick={() => toggle(r)}>{r.active ? "Desativar" : "Ativar"}</Button>{" "}
                                                <Button size="sm" color="outline-danger" onClick={() => setDeleting(r)} aria-label={`Apagar ${r.label}`}><i className="ri-delete-bin-line" /></Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    )}
                </PageCard>
                <Card className="mt-3" data-testid="rules-history">
                    <CardBody>
                        <h6 className="mb-2">Histórico</h6>
                        {events.length === 0 ? <p className="text-muted fs-13 mb-0">Sem mudanças.</p> : (
                            <ul className="list-unstyled fs-13 mb-0">
                                {events.map((e) => (
                                    <li key={e.id} className="py-1 border-bottom">
                                        <strong>{ACTION[e.action] ?? e.action}</strong> · regra {e.rule_id} · {e.user ?? "XPLENDOR"} · {e.at ? new Date(e.at).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" }) : "—"}
                                        {e.reason && <div className="text-muted fs-12">{e.reason}</div>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardBody>
                </Card>
            </Container>

            <Modal isOpen={open} toggle={() => !busy && setOpen(false)} centered data-testid="rule-modal">
                <ModalHeader toggle={() => !busy && setOpen(false)}>Criar regra</ModalHeader>
                <ModalBody>
                    <Row className="g-2">
                        <Col xs={12}>
                            <Label className="form-label mb-1" for="rule-type">Quando</Label>
                            <XSelect<string> id="rule-type" ariaLabel="Quando" value={form.match_type} placeholder="Escolher…" searchable={false} options={TYPES}
                                onChange={(v) => setForm((f) => ({ ...f, match_type: v as FinanceRule["match_type"], values: "" }))} />
                        </Col>
                        {form.match_type && form.match_type !== "entidade" && (
                            <Col xs={12}>
                                <Label className="form-label mb-1" for="rule-values">{form.match_type === "artigo" ? "Códigos dos artigos (separados por vírgulas)" : form.match_type === "familia" ? "Família (com as subfamílias)" : "Tipo de documento"}</Label>
                                {valueOptions
                                    ? <XSelect<string> id="rule-values" ariaLabel="Valor" value={form.values} placeholder="Escolher…" options={valueOptions} onChange={(v) => setForm((f) => ({ ...f, values: v }))} />
                                    : <Input id="rule-values" value={form.values} placeholder="Por exemplo, 100833, 100834" onChange={(e) => setForm((f) => ({ ...f, values: e.target.value }))} />}
                            </Col>
                        )}
                        {form.match_type && (
                            <Col xs={12}>
                                <Label className="form-label mb-1" for="rule-entity">{form.match_type === "entidade" ? "Entidade" : "Só desta entidade (opcional)"}</Label>
                                <XSelect<string> id="rule-entity" ariaLabel="Entidade" value={form.entity} placeholder={form.match_type === "entidade" ? "Escolher…" : "Qualquer entidade"}
                                    options={[...(form.match_type === "entidade" ? [] : [{ value: "", label: "Qualquer entidade" }]), ...opts.entities]} onChange={(v) => setForm((f) => ({ ...f, entity: v }))} />
                            </Col>
                        )}
                        <Col xs={12}>
                            <Label className="form-label mb-1" for="rule-group">Grupo</Label>
                            <XSelect<string> id="rule-group" ariaLabel="Grupo" value={form.group} placeholder="Escolher…" searchable={false} options={opts.groups} onChange={(v) => setForm((f) => ({ ...f, group: v }))} />
                        </Col>
                        <Col xs={12}>
                            <Label className="form-label mb-1" for="rule-label">Nome (opcional)</Label>
                            <Input id="rule-label" maxLength={255} value={form.label} onChange={(e) => setForm((f) => ({ ...f, label: e.target.value }))} placeholder="Por exemplo, Artigos da folha de obrigações" />
                        </Col>
                    </Row>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setOpen(false)} disabled={busy}>Cancelar</Button>
                    <ReasonButton color="primary" reason={reason} disabled={busy} onClick={submit} data-testid="rule-save">{busy ? <Spinner size="sm" /> : "Criar regra"}</ReasonButton>
                </ModalFooter>
            </Modal>
            <Modal isOpen={!!deleting} toggle={() => !busy && setDeleting(null)} centered>
                <ModalHeader toggle={() => !busy && setDeleting(null)}>Apagar a regra?</ModalHeader>
                <ModalBody>{deleting?.label}. Os gastos voltam a seguir a família do PingWin. A mudança fica no histórico.</ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setDeleting(null)} disabled={busy}>Cancelar</Button>
                    <Button color="danger" onClick={remove} disabled={busy}>{busy ? <Spinner size="sm" /> : "Apagar"}</Button>
                </ModalFooter>
            </Modal>
        </div>
    );
}
