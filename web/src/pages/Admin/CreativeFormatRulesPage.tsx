import { useCallback, useEffect, useMemo, useState } from "react";
import { Badge, Button, Card, CardBody, Col, Container, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner, Table } from "reactstrap";
import Select from "react-select";
import { toast, ToastContainer } from "react-toastify";
import BreadCrumb from "Components/Common/BreadCrumb";
import { createCreativeFormatRule, deleteCreativeFormatRule, getCreativeFormatRules, updateCreativeFormatRule } from "helpers/laravel_helper";
import { reactSelectTheme } from "helpers/reactSelectStyles";
import type { CreativeFormatRule } from "common/models/brandAssistants.model";

/**
 * Regras de formato (referência de mercado) do "Sugerir criativo": por rede e faixa de
 * seguidores, que formato tende a ter mais interação, com a fonte. Só o root.
 */

type Channel = "instagram" | "facebook";
type FormOption = { value: string; label: string };
type RuleForm = {
    id?: number; channel: Channel; followers_min: string; followers_max: string; format_key: string;
    engagement_rate: string; rank: string; note: string; source_label: string; source_url: string; is_active: boolean;
};

const CHANNELS: { value: Channel; label: string }[] = [{ value: "instagram", label: "Instagram" }, { value: "facebook", label: "Facebook" }];
const EMPTY: RuleForm = {
    channel: "instagram", followers_min: "0", followers_max: "", format_key: "", engagement_rate: "", rank: "1",
    note: "", source_label: "", source_url: "", is_active: true,
};

const int = (v: number) => v.toLocaleString("pt-PT");
const bandLabel = (r: CreativeFormatRule) =>
    r.followers_max === null ? `${int(r.followers_min)} ou mais` : `${int(r.followers_min)} a ${int(r.followers_max)}`;

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

export default function CreativeFormatRulesPage() {
    document.title = "Regras de formato | Xplendor";
    const [rules, setRules] = useState<CreativeFormatRule[]>([]);
    const [formats, setFormats] = useState<Record<Channel, FormOption[]>>({ instagram: [], facebook: [] });
    const [loading, setLoading] = useState(true);
    const [form, setForm] = useState<RuleForm | null>(null);
    const [saving, setSaving] = useState(false);

    const load = useCallback(() => {
        setLoading(true);
        getCreativeFormatRules()
            .then((r: any) => { setRules(r?.data?.rules ?? []); setFormats(r?.data?.formats ?? { instagram: [], facebook: [] }); })
            .catch(() => toast.error("Não foi possível carregar as regras."))
            .finally(() => setLoading(false));
    }, []);
    useEffect(() => { load(); }, [load]);

    const labelOf = useMemo(() => {
        const all = [...formats.instagram, ...formats.facebook];
        return (k: string) => all.find((f) => f.value === k)?.label ?? k;
    }, [formats]);

    const edit = (r: CreativeFormatRule) => setForm({
        id: r.id, channel: r.channel, followers_min: String(r.followers_min), followers_max: r.followers_max === null ? "" : String(r.followers_max),
        format_key: r.format_key, engagement_rate: r.engagement_rate === null ? "" : String(r.engagement_rate), rank: String(r.rank),
        note: r.note ?? "", source_label: r.source_label, source_url: r.source_url ?? "", is_active: r.is_active,
    });

    const save = async () => {
        if (!form) return;
        const payload = {
            channel: form.channel, followers_min: Number(form.followers_min || 0),
            followers_max: form.followers_max === "" ? null : Number(form.followers_max),
            format_key: form.format_key, engagement_rate: form.engagement_rate === "" ? null : Number(form.engagement_rate.replace(",", ".")),
            rank: Number(form.rank || 1), note: form.note || null, source_label: form.source_label, source_url: form.source_url || null, is_active: form.is_active,
        };
        setSaving(true);
        try {
            if (form.id) await updateCreativeFormatRule(form.id, payload); else await createCreativeFormatRule(payload);
            toast.success("Regra guardada.");
            setForm(null);
            load();
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível guardar a regra."));
        } finally {
            setSaving(false);
        }
    };

    const remove = async (r: CreativeFormatRule) => {
        if (!window.confirm(`Apagar a regra "${labelOf(r.format_key)}" (${bandLabel(r)})?`)) return;
        try {
            await deleteCreativeFormatRule(r.id);
            toast.success("Regra apagada.");
            load();
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível apagar a regra."));
        }
    };

    const formatOptions = form ? formats[form.channel] : [];

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <BreadCrumb title="Regras de formato" pageTitle="Administração" pageLink="/admin" />
                <Card>
                    <CardBody>
                        <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <p className="text-muted fs-13 mb-0">
                                Referência de mercado usada pelo "Sugerir criativo" quando a conta ainda não tem histórico próprio.
                                Cada regra indica a fonte, que é mostrada a quem recebe a sugestão.
                            </p>
                            <Button color="primary" onClick={() => setForm({ ...EMPTY })}><i className="ri-add-line me-1" />Nova regra</Button>
                        </div>
                        {loading ? <div className="text-center py-4"><Spinner size="sm" /></div> : (
                            <div className="table-responsive">
                                <Table className="align-middle table-nowrap mb-0 fs-13">
                                    <thead className="text-muted table-light">
                                        <tr>
                                            <th>Rede</th><th>Seguidores</th><th>Ordem</th><th>Formato</th><th className="text-end">Taxa de interação</th>
                                            <th>Nota</th><th>Fonte</th><th>Estado</th><th />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rules.map((r) => (
                                            <tr key={r.id}>
                                                <td>{CHANNELS.find((c) => c.value === r.channel)?.label}</td>
                                                <td>{bandLabel(r)}</td>
                                                <td>{r.rank}</td>
                                                <td>{labelOf(r.format_key)}</td>
                                                <td className="text-end">{r.engagement_rate === null ? <span className="text-muted">Sem valor</span> : `${r.engagement_rate.toLocaleString("pt-PT", { minimumFractionDigits: 2 })}%`}</td>
                                                <td className="text-wrap" style={{ maxWidth: 260 }}>{r.note}</td>
                                                <td className="text-wrap" style={{ maxWidth: 220 }}>
                                                    {r.source_url ? <a href={r.source_url} target="_blank" rel="noopener noreferrer">{r.source_label}</a> : r.source_label}
                                                </td>
                                                <td>{r.is_active ? <Badge color="success-subtle" className="text-success">Ativa</Badge> : <Badge color="light" className="text-muted">Inativa</Badge>}</td>
                                                <td className="text-end">
                                                    <Button size="sm" color="soft-primary" className="me-1" onClick={() => edit(r)} aria-label="Editar"><i className="ri-pencil-line" /></Button>
                                                    <Button size="sm" color="soft-danger" onClick={() => remove(r)} aria-label="Apagar"><i className="ri-delete-bin-line" /></Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </Table>
                            </div>
                        )}
                    </CardBody>
                </Card>
            </Container>

            <Modal isOpen={form !== null} toggle={() => setForm(null)} centered>
                <ModalHeader toggle={() => setForm(null)}>{form?.id ? "Editar regra" : "Nova regra"}</ModalHeader>
                {form && (
                    <ModalBody>
                        <Row className="g-2">
                            <Col sm={6}>
                                <Label>Rede</Label>
                                <Select styles={reactSelectTheme} menuPortalTarget={document.body} options={CHANNELS} isSearchable={false}
                                    value={CHANNELS.find((c) => c.value === form.channel)}
                                    onChange={(o: any) => setForm({ ...form, channel: o.value, format_key: "" })} />
                            </Col>
                            <Col sm={6}>
                                <Label>Formato</Label>
                                <Select styles={reactSelectTheme} menuPortalTarget={document.body} options={formatOptions} isSearchable={false}
                                    placeholder="Escolha o formato"
                                    value={formatOptions.find((o) => o.value === form.format_key) ?? null}
                                    onChange={(o: any) => setForm({ ...form, format_key: o?.value ?? "" })} />
                            </Col>
                            <Col sm={4}><Label>Seguidores, de</Label><Input type="number" min={0} value={form.followers_min} onChange={(e) => setForm({ ...form, followers_min: e.target.value })} /></Col>
                            <Col sm={4}><Label>até</Label><Input type="number" min={0} placeholder="Sem limite" value={form.followers_max} onChange={(e) => setForm({ ...form, followers_max: e.target.value })} /></Col>
                            <Col sm={4}><Label>Ordem</Label><Input type="number" min={1} max={20} value={form.rank} onChange={(e) => setForm({ ...form, rank: e.target.value })} /></Col>
                            <Col sm={6}><Label>Taxa de interação (%)</Label><Input inputMode="decimal" placeholder="Ex.: 6,81" value={form.engagement_rate} onChange={(e) => setForm({ ...form, engagement_rate: e.target.value })} /></Col>
                            <Col sm={6} className="d-flex align-items-end">
                                <div className="form-check form-switch mb-2">
                                    <Input type="switch" className="form-check-input" id="rule-active" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
                                    <Label className="form-check-label" for="rule-active">Regra ativa</Label>
                                </div>
                            </Col>
                            <Col xs={12}><Label>Nota</Label><Input maxLength={500} value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} /></Col>
                            <Col xs={12}><Label>Fonte</Label><Input maxLength={255} value={form.source_label} onChange={(e) => setForm({ ...form, source_label: e.target.value })} placeholder="Nome do estudo" /></Col>
                            <Col xs={12}><Label>Ligação para a fonte</Label><Input maxLength={500} value={form.source_url} onChange={(e) => setForm({ ...form, source_url: e.target.value })} placeholder="https://" /></Col>
                        </Row>
                    </ModalBody>
                )}
                <ModalFooter>
                    <Button color="light" onClick={() => setForm(null)}>Cancelar</Button>
                    <Button color="primary" onClick={save} disabled={saving}>{saving && <Spinner size="sm" className="me-1" />}Guardar</Button>
                </ModalFooter>
            </Modal>
        </div>
    );
}
