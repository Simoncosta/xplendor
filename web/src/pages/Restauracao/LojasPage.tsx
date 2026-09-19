import { useCallback, useEffect, useMemo, useState } from "react";
import { Card, Container, Row, Col, Spinner, Modal, ModalHeader, ModalBody, ModalFooter } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import {
    getPingwinLocations, createPingwinLocation, updatePingwinLocation, deletePingwinLocation, syncCoverManager, getCoverManager,
} from "helpers/laravel_helper";
import { PingwinLocationEntity } from "common/models/pingwin.model";

const yesterdayIso = () => {
    const d = new Date();
    d.setDate(d.getDate() - 1);
    return d.toISOString().slice(0, 10);
};

const fmtDateTime = (d?: string | null) =>
    d ? new Date(d).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—";

/**
 * XPLENDOR — Restauração › Lojas. Tabela LIMPA + botão "Adicionar loja"; o
 * formulário (adicionar/editar) vive num MODAL. O botão "Sincronizar reservas"
 * sincroniza TODAS as lojas (CoverManagerService::sync) e a tabela mostra a
 * última sincronização por loja. (A flag do ticket médio vive em Integrações.)
 */

const emptyForm = () => ({ winrest_store_id: "", winrest_name: "", display_name: "", opened_on: "", is_active: true, cm_slug: "", cm_token: "" });

export default function LojasPage() {
    document.title = "Lojas | Restauração | Xplendor";

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        try { return Number(JSON.parse(authUser).company_id || 0); } catch { return 0; }
    }, []);

    const [rows, setRows] = useState<PingwinLocationEntity[]>([]);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [modalOpen, setModalOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [form, setForm] = useState(emptyForm());
    const [reservDate, setReservDate] = useState<string>(yesterdayIso());
    const [syncingReservs, setSyncingReservs] = useState(false);
    // A empresa tem integração CoverManager (token de empresa)? Gate do slug no modal.
    const [cmIntegrationConnected, setCmIntegrationConnected] = useState(false);

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getPingwinLocations(companyId);
            setRows(res?.data ?? []);
        } catch {
            setRows([]);
        } finally {
            setLoading(false);
        }
    }, [companyId]);

    useEffect(() => {
        fetchRows();
        if (companyId) {
            getCoverManager(companyId)
                .then((r: any) => setCmIntegrationConnected(!!r?.data?.connected))
                .catch(() => setCmIntegrationConnected(false));
        }
    }, [fetchRows, companyId]);

    const setField = (k: string, v: any) => setForm((p) => ({ ...p, [k]: v }));

    const openAdd = () => { setEditingId(null); setForm(emptyForm()); setModalOpen(true); };

    const openEdit = (loc: PingwinLocationEntity) => {
        setEditingId(loc.id);
        setForm({
            winrest_store_id: loc.winrest_store_id ?? "",
            winrest_name: loc.winrest_name ?? "",
            display_name: loc.display_name ?? "",
            opened_on: (loc.opened_on ?? "").slice(0, 10),
            is_active: !!loc.is_active,
            cm_slug: loc.cm_slug ?? "",
            cm_token: "", // token nunca é pré-preenchido (em branco = manter)
        });
        setModalOpen(true);
    };

    const submit = async () => {
        if (!form.winrest_store_id.trim()) { toast.error("O ID PingWin da loja é obrigatório."); return; }
        setSaving(true);
        try {
            const payload: Record<string, any> = {
                winrest_store_id: form.winrest_store_id.trim(),
                is_active: form.is_active ? 1 : 0, // 1/0 — aceite pelo Laravel em multipart e JSON
            };
            if (form.winrest_name.trim()) payload.winrest_name = form.winrest_name.trim();
            if (form.display_name.trim()) payload.display_name = form.display_name.trim();
            if (form.opened_on) payload.opened_on = form.opened_on;
            if (form.cm_slug.trim()) payload.cm_slug = form.cm_slug.trim();
            if (form.cm_token.trim()) payload.cm_token = form.cm_token.trim();

            if (editingId) {
                await updatePingwinLocation(companyId, editingId, payload);
                toast.success("Loja atualizada.");
            } else {
                await createPingwinLocation(companyId, payload);
                toast.success("Loja cadastrada.");
            }
            setModalOpen(false);
            await fetchRows();
        } catch (e: any) {
            const msg = e?.errors && Object.values(e.errors)?.[0] ? (Object.values(e.errors)[0] as any)[0] : e?.message;
            toast.error(msg ?? "Não foi possível guardar a loja.");
        } finally {
            setSaving(false);
        }
    };

    const runReservSync = async () => {
        if (!companyId || !reservDate) return;
        setSyncingReservs(true);
        try {
            // Sincroniza TODAS as lojas com CoverManager (o serviço percorre todas).
            const res: any = await syncCoverManager(companyId, reservDate);
            const d = res?.data;
            toast.success(`Reservas sincronizadas (${d?.synced ?? 0} loja(s))${d?.failed?.length ? ` — falhou: ${d.failed.join(", ")}` : ""}.`);
            await fetchRows();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível sincronizar as reservas.");
        } finally {
            setSyncingReservs(false);
        }
    };

    const remove = async (loc: PingwinLocationEntity) => {
        if (!window.confirm(`Remover a loja "${loc.display_name || loc.winrest_store_id}"?`)) return;
        try {
            await deletePingwinLocation(companyId, loc.id);
            toast.success("Loja removida.");
            await fetchRows();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível remover a loja.");
        }
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                {/* Cabeçalho (padrão do sistema) + ação principal. */}
                <Row>
                    <Col xs={12}>
                        <div className="page-title-box d-sm-flex align-items-center justify-content-between">
                            <h4 className="mb-sm-0">Lojas</h4>
                            <button className="btn btn-primary" onClick={openAdd}>
                                <i className="ri-add-line me-1" /> Adicionar loja
                            </button>
                        </div>
                    </Col>
                </Row>

                {/* Tabela limpa (só lista + ações). */}
                <Row className="g-3 pb-5 mb-5">
                    <Col xs={12}>
                        <Card className="mb-0">
                            <div className="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <h5 className="card-title mb-0">Lojas cadastradas {loading && <Spinner size="sm" className="ms-1" />}</h5>
                                {/* Sincronizar reservas (CoverManager) — TODAS as lojas, para uma data. */}
                                <div className="d-flex align-items-center gap-2">
                                    <input type="date" className="form-control form-control-sm" style={{ width: 150 }} value={reservDate} max={yesterdayIso()} onChange={(e) => setReservDate(e.target.value)} disabled={syncingReservs} />
                                    <button className="btn btn-sm btn-soft-primary" onClick={runReservSync} disabled={syncingReservs || !reservDate}>
                                        {syncingReservs ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-calendar-event-line me-1" /> Sincronizar reservas (todas)</>}
                                    </button>
                                </div>
                            </div>
                            <div className="table-responsive">
                                {/* Mesmo mecanismo das tabelas que funcionam (Carros/Leads):
                                    table-bordered + thead "text-muted table-light" → header visível
                                    (claro e escuro). Sem table-light o thead fica transparente. */}
                                <table className="table table-bordered table-hover align-middle mb-0">
                                    <thead className="text-muted table-light">
                                        <tr>
                                            <th>ID PingWin</th>
                                            <th>Nome PingWin</th>
                                            <th>Nome amigável</th>
                                            <th>Abertura</th>
                                            <th>Estado</th>
                                            <th>Reservas</th>
                                            <th>Última sincronização</th>
                                            <th className="text-end">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {!loading && rows.length === 0 ? (
                                            <tr><td colSpan={8} className="text-center text-muted py-4">Sem lojas. Usa <strong>“Adicionar loja”</strong>.</td></tr>
                                        ) : rows.map((loc) => (
                                            <tr key={loc.id}>
                                                <td className="fw-medium text-break" style={{ maxWidth: 180 }}>{loc.winrest_store_id}</td>
                                                <td>{loc.winrest_name || "—"}</td>
                                                <td>{loc.display_name || "—"}</td>
                                                <td>{loc.opened_on ? loc.opened_on.slice(0, 10) : "—"}</td>
                                                {/* Badges no padrão que já funciona (LeadStatusBadge): bg-{cor}-subtle + text-{cor}
                                                    — legível em claro e escuro. O 'badge-soft-*' não pintava fundo → texto branco invisível. */}
                                                <td>{loc.is_active ? <span className="badge bg-success-subtle text-success">Ativa</span> : <span className="badge bg-secondary-subtle text-secondary">Inativa</span>}</td>
                                                <td>{loc.cm_connected ? <span className="badge bg-success-subtle text-success">CoverManager</span> : <span className="badge bg-secondary-subtle text-secondary">—</span>}</td>
                                                <td className="text-muted fs-12">{fmtDateTime(loc.cm_last_synced_at)}</td>
                                                <td className="text-end">
                                                    <button className="btn btn-sm btn-soft-primary me-1" onClick={() => openEdit(loc)}><i className="ri-pencil-line" /></button>
                                                    <button className="btn btn-sm btn-soft-danger" onClick={() => remove(loc)}><i className="ri-delete-bin-line" /></button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </Card>
                    </Col>
                </Row>

                {/* Modal adicionar/editar loja. */}
                <Modal isOpen={modalOpen} toggle={() => !saving && setModalOpen(false)} size="lg" centered>
                    <ModalHeader toggle={() => !saving && setModalOpen(false)}>{editingId ? "Editar loja" : "Adicionar loja"}</ModalHeader>
                    <ModalBody>
                        <Row className="g-2">
                            <Col md={6}>
                                <label className="form-label fs-12 mb-1">ID PingWin (winrest_store_id) *</label>
                                <input className="form-control" value={form.winrest_store_id} onChange={(e) => setField("winrest_store_id", e.target.value)} placeholder="ex.: 5849..." disabled={saving} />
                            </Col>
                            <Col md={6}>
                                <label className="form-label fs-12 mb-1">Nome PingWin (para ligar as vendas)</label>
                                <input className="form-control" value={form.winrest_name} onChange={(e) => setField("winrest_name", e.target.value)} placeholder="nome exato no PingWin" disabled={saving} />
                            </Col>
                            <Col md={5}>
                                <label className="form-label fs-12 mb-1">Nome amigável</label>
                                <input className="form-control" value={form.display_name} onChange={(e) => setField("display_name", e.target.value)} placeholder="ex.: Yuko Lisboa" disabled={saving} />
                            </Col>
                            <Col md={4}>
                                <label className="form-label fs-12 mb-1">Data de abertura</label>
                                <input type="date" className="form-control" value={form.opened_on} max={new Date().toISOString().slice(0, 10)} onChange={(e) => setField("opened_on", e.target.value)} disabled={saving} />
                            </Col>
                            <Col md={3} className="d-flex align-items-end">
                                <div className="form-check form-switch fs-16">
                                    <input className="form-check-input" type="checkbox" role="switch" id="loc-active" checked={form.is_active} onChange={(e) => setField("is_active", e.target.checked)} disabled={saving} />
                                    <label className="form-check-label fs-13" htmlFor="loc-active">{form.is_active ? "Ativa" : "Inativa"}</label>
                                </div>
                            </Col>
                        </Row>

                        <h6 className="fw-semibold text-uppercase text-muted fs-11 mt-3 mb-2">CoverManager (reservas)</h6>
                        {!cmIntegrationConnected ? (
                            <div className="alert alert-warning py-2 px-3 fs-12 mb-0" role="alert">
                                <i className="ri-information-line me-1" />
                                Liga o <strong>CoverManager</strong> na empresa primeiro (Integrações) para configurar o slug das lojas.
                            </div>
                        ) : (
                            <Row className="g-2">
                                <Col md={6}>
                                    <label className="form-label fs-12 mb-1">Slug CoverManager</label>
                                    <input className="form-control" value={form.cm_slug} onChange={(e) => setField("cm_slug", e.target.value)} placeholder="ex.: yuko-baixa" disabled={saving} />
                                </Col>
                                <Col md={6}>
                                    <label className="form-label fs-12 mb-1">Token da loja (override — opcional)</label>
                                    <input type="password" className="form-control" value={form.cm_token} onChange={(e) => setField("cm_token", e.target.value)}
                                        placeholder={editingId ? "•••••••• (em branco = usar o da empresa)" : "vazio = usar o token da empresa"} autoComplete="new-password" disabled={saving} />
                                    <div className="form-text fs-11">Vazio → usa o token da empresa. O token guardado não é mostrado.</div>
                                </Col>
                            </Row>
                        )}
                    </ModalBody>
                    <ModalFooter>
                        <button className="btn btn-light" onClick={() => setModalOpen(false)} disabled={saving}>Cancelar</button>
                        <button className="btn btn-primary" onClick={submit} disabled={saving}>
                            {saving ? <><Spinner size="sm" className="me-1" /> A guardar…</> : (editingId ? <><i className="ri-save-line me-1" /> Guardar loja</> : <><i className="ri-add-line me-1" /> Adicionar loja</>)}
                        </button>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
}
