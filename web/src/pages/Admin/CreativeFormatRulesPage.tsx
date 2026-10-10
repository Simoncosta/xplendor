import { useCallback, useEffect, useMemo, useState } from "react";
import { Badge, Button, Col, Container, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import XSelect from "Components/Common/Select";
import ActionsMenu from "Components/Common/ActionsMenu";
import { confirmAction } from "helpers/swal";
import { createCreativeFormatRule, deleteCreativeFormatRule, getCreativeFormatRules, updateCreativeFormatRule } from "helpers/laravel_helper";
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
        const ok = await confirmAction({ title: `Apagar a regra "${labelOf(r.format_key)}"?`, text: `${bandLabel(r)}. Esta ação não se desfaz.`, confirmText: "Apagar", icon: "warning", confirmVariant: "danger" });
        if (!ok) return;
        try {
            await deleteCreativeFormatRule(r.id);
            toast.success("Regra apagada.");
            load();
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível apagar a regra."));
        }
    };

    const formatOptions = form ? formats[form.channel] : [];

    const cols = useDataColumns<CreativeFormatRule>("administracao.regras-formato", [
        { id: "channel", header: "Rede", value: (r) => CHANNELS.find((c) => c.value === r.channel)?.label ?? r.channel },
        { id: "band", header: "Seguidores", value: (r) => r.followers_min, cell: (r) => bandLabel(r), nowrap: true },
        { id: "rank", header: "Ordem", value: (r) => r.rank, align: "end" },
        { id: "format", header: "Formato", value: (r) => labelOf(r.format_key), hideable: false, mobile: "title" },
        { id: "rate", header: "Taxa de interação", value: (r) => r.engagement_rate ?? undefined, align: "end", nowrap: true,
            cell: (r) => (r.engagement_rate === null ? <span className="text-muted">Sem valor</span> : `${r.engagement_rate.toLocaleString("pt-PT", { minimumFractionDigits: 2 })}%`) },
        { id: "note", header: "Nota", value: (r) => r.note ?? "", cell: (r) => <span className="text-wrap d-inline-block" style={{ maxWidth: 260 }}>{r.note}</span>, defaultVisible: false },
        { id: "source", header: "Fonte", value: (r) => r.source_label,
            cell: (r) => <span className="text-wrap d-inline-block" style={{ maxWidth: 220 }}>{r.source_url ? <a href={r.source_url} target="_blank" rel="noopener noreferrer">{r.source_label}</a> : r.source_label}</span> },
        { id: "active", header: "Estado", value: (r) => (r.is_active ? 1 : 0),
            cell: (r) => (r.is_active ? <Badge color="success-subtle" className="text-success">Ativa</Badge> : <Badge color="light" className="text-muted">Inativa</Badge>) },
    ] as DTColumn<CreativeFormatRule>[]);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Regras de formato" breadcrumbs={[{ label: "Administração", to: "/admin" }]}
                    info={<>Referência de mercado usada pelo "Sugerir criativo" quando a conta ainda não tem histórico próprio. Cada regra indica a fonte, que é mostrada a quem recebe a sugestão.</>} />
                <PageCard
                    title="Regras"
                    status={!loading ? <>{rules.length} regra{rules.length === 1 ? "" : "s"}{rules.some((r) => !r.is_active) ? ` · ${rules.filter((r) => !r.is_active).length} inativa(s)` : ""}</> : undefined}
                    loading={loading && rules.length > 0}
                    actions={<>
                        {cols.selector}
                        <Button size="sm" color="primary" onClick={() => setForm({ ...EMPTY })}><i className="ri-add-line me-1" />Nova regra</Button>
                    </>}
                >
                    <DataTable
                        columns={cols}
                        data={rules}
                        rowKey={(r) => r.id}
                        loading={loading}
                        caption="Regras de formato"
                        // Estado de vazio (UI-2d): antes a tabela ficava só com o cabeçalho.
                        empty={{
                            message: "Ainda não há regras de formato. Sem elas, o \"Sugerir criativo\" só usa o histórico de cada conta.",
                            action: <Button size="sm" color="outline-primary" onClick={() => setForm({ ...EMPTY })}><i className="ri-add-line me-1" />Nova regra</Button>,
                        }}
                        rowActions={(r) => (
                            <>
                                <Button size="sm" color="outline-primary" onClick={() => edit(r)} aria-label="Editar"><i className="ri-pencil-line" /></Button>
                                <ActionsMenu size="sm" label={`Mais ações: ${labelOf(r.format_key)}`} items={[
                                    { label: "Apagar", icon: "ri-delete-bin-line", danger: true, onClick: () => void remove(r) },
                                ]} />
                            </>
                        )}
                    />
                </PageCard>
            </Container>

            <Modal isOpen={form !== null} toggle={() => setForm(null)} centered>
                <ModalHeader toggle={() => setForm(null)}>{form?.id ? "Editar regra" : "Nova regra"}</ModalHeader>
                {form && (
                    <ModalBody>
                        <Row className="g-2">
                            <Col sm={6}>
                                <Label>Rede</Label>
                                <XSelect ariaLabel="Rede" options={CHANNELS} searchable={false} value={form.channel}
                                    onChange={(v) => setForm({ ...form, channel: v as typeof form.channel, format_key: "" })} />
                            </Col>
                            <Col sm={6}>
                                <Label>Formato</Label>
                                <XSelect ariaLabel="Formato" options={formatOptions} searchable={false} placeholder="Escolha o formato"
                                    value={form.format_key || null} onChange={(v) => setForm({ ...form, format_key: v })} />
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
