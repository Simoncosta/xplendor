import { useCallback, useEffect, useMemo, useState } from "react";
import { Alert, Button, Col, Container, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import XSelect from "Components/Common/Select";
import InfoTip from "Components/Common/InfoTip";
import Skeleton from "Components/Common/Skeleton";
import { createWarehouse, deleteWarehouse, getWarehouses, setStoreWarehouse, updateWarehouse } from "helpers/laravel_helper";
import { WarehouseRow, WarehousesResponse } from "common/models/stock.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import AuditButton from "pages/Auditoria/AuditButton";
import AuditCountCell, { useAuditCounts } from "pages/Auditoria/AuditCountCell";
import { fmtDate, fmtWhen } from "pages/Auditoria/auditFormat";
import { WRITE_STATUS, useStockWrite, writeMessage } from "./stock/stockFormat";

/**
 * XPLENDOR — Restauração › Armazéns (Fatia 7a). Os armazéns do PingWin (do espelho, lido de noite e
 * depois de cada gravação): criar, editar (nome, lojas servidas, valorização) e apagar (só sem stock,
 * com o nome escrito outra vez). Cada gravação vai para o PingWin, uma de cada vez, e é confirmada por
 * releitura. Em baixo, o armazém por omissão de cada loja, que a XPLENDOR usa no consumo teórico.
 */

const COSTMETHOD_TIP = "Como o PingWin valoriza o stock deste armazém: PCM (custo médio das entradas) ou PCU (último custo). O PingWin só mostrou estes dois valores.";

export default function ArmazensPage() {
    document.title = "Armazéns | Cadastros | Xplendor";
    const companyId = useWorkingCompanyId();
    const [data, setData] = useState<WarehousesResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [form, setForm] = useState<{ mode: "create" | "edit"; row?: WarehouseRow } | null>(null);
    const [removing, setRemoving] = useState<WarehouseRow | null>(null);
    const [auditKey, setAuditKey] = useState(0);
    const [savingStore, setSavingStore] = useState<string | null>(null);

    const load = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        setError(null);
        try {
            const res: any = await getWarehouses(companyId);
            setData(res?.data ?? null);
        } catch (e: any) {
            setError(e?.message ?? "Não foi possível carregar os armazéns.");
        } finally {
            setLoading(false);
        }
    }, [companyId]);
    useEffect(() => { load(); }, [load]);

    const active = useMemo(() => (data?.warehouses ?? []).filter((w) => !w.deleted), [data]);
    const auditCounts = useAuditCounts(companyId, "armazem", active.map((w) => w.id), auditKey);
    const storeName = (id: string) => data?.known_stores.find((s) => s.id === id)?.name ?? id;
    const blocked = data?.allowed_names.length === 1 && data.allowed_names[0] === "0";
    const warehouseName = (id: string | null) => active.find((w) => w.id === id)?.description ?? id ?? "";
    // A primeira opção é "como o PingWin diz" (sem escolha na XPLENDOR): mostra qual é, quando há um.
    const pingwinLabel = (s: { source: string | null; warehouse_id: string | null; candidates: string[] }) => {
        if (s.source === "pingwin") return `Como no PingWin (${warehouseName(s.warehouse_id)})`;
        const only = s.candidates.length === 1 ? s.candidates[0] : null;
        if (s.source === "xplendor") return only ? `Como no PingWin (${warehouseName(only)})` : "Como no PingWin (por definir)";
        return "Escolher…";
    };

    const onStoreDefault = async (storeId: string, warehouseId: string) => {
        if (!companyId) return;
        setSavingStore(storeId);
        try {
            await setStoreWarehouse(companyId, storeId, warehouseId || null);
            toast.success("Armazém da loja guardado na XPLENDOR.");
            load();
        } catch (e: any) {
            toast.error(e?.response?.data?.message ?? e?.message ?? "Não foi possível guardar.");
        } finally {
            setSavingStore(null);
        }
    };

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader
                    title="Armazéns"
                    breadcrumbs={[{ label: "Cadastros" }]}
                    info="Os armazéns do PingWin: onde entra e de onde sai o stock. As alterações gravam-se no PingWin, uma de cada vez, e confirmam-se por releitura."
                />
                <Row>
                    <Col xs={12}>
                        <PageCard
                            title="Armazéns do PingWin"
                            loading={loading && !!data}
                            status={<>Lidos do PingWin: {active[0]?.synced_at ? fmtWhen(active[0].synced_at) : "ainda não lidos"}</>}
                            actions={<>
                                <AuditButton companyId={companyId} area="restauracao" origin="armazens" onFinished={() => setAuditKey((k) => k + 1)} />
                                {data?.can_edit && (
                                    <Button size="sm" color="primary" onClick={() => setForm({ mode: "create" })} disabled={blocked} title={blocked ? data?.create_guard?.reason : undefined} data-testid="warehouse-new">
                                        <i className="ri-add-line me-1" />Novo armazém
                                    </Button>
                                )}
                            </>}
                        >
                            {error && !data ? (
                                <div className="p-4 text-center">
                                    <p className="text-danger mb-2">{error}</p>
                                    <Button size="sm" color="outline-primary" onClick={load}><i className="ri-refresh-line me-1" />Tentar de novo</Button>
                                </div>
                            ) : !data ? (
                                <div className="p-3"><Skeleton variant="lines" lines={3} label="A carregar os armazéns" /></div>
                            ) : (
                                <div className="table-responsive">
                                    <Table className="align-middle mb-0" data-testid="warehouses-table">
                                        <thead className="table-light">
                                            <tr>
                                                <th>Código</th>
                                                <th>Armazém</th>
                                                <th>Lojas servidas</th>
                                                <th>Valorização <InfoTip label="Sobre a valorização" text={COSTMETHOD_TIP} /></th>
                                                <th>Armazém por omissão de</th>
                                                <th className="text-end">Artigos com stock</th>
                                                <th>Última valorização</th>
                                                <th className="text-center">Auditoria</th>
                                                <th />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {active.length === 0 && (
                                                <tr><td colSpan={9} className="text-center text-muted py-4">Ainda não há armazéns lidos do PingWin. A leitura corre de noite.</td></tr>
                                            )}
                                            {active.map((w) => (
                                                <tr key={w.id} data-warehouse={w.id}>
                                                    <td className="text-nowrap">{w.code ?? "—"}</td>
                                                    <td className="fw-medium">{w.description}</td>
                                                    <td>
                                                        <div className="d-flex flex-wrap gap-1">
                                                            {w.stores.filter((s) => s.served).map((s) => <span key={s.store_id} className="badge bg-info-subtle text-info">{s.name ?? s.code}</span>)}
                                                            {!w.stores.some((s) => s.served) && <span className="text-muted">—</span>}
                                                        </div>
                                                    </td>
                                                    <td className="text-nowrap fs-13">Existências: {w.inv_costmethod_label ?? "—"}<br />Saídas: {w.exit_costmethod_label ?? "—"}</td>
                                                    <td className="fs-13">{w.default_for.length ? w.default_for.join(", ") : <span className="text-muted">—</span>}</td>
                                                    <td className="text-end">{w.articles_with_stock.toLocaleString("pt-PT")}</td>
                                                    <td className="text-nowrap">{fmtDate(w.last_valorization)}</td>
                                                    <td className="text-center"><AuditCountCell counts={auditCounts} entityType="armazem" entityId={w.id} label={w.description} /></td>
                                                    <td className="text-end text-nowrap">
                                                        {data.can_edit && (
                                                            <>
                                                                <Button size="sm" color="outline-primary" className="me-1" onClick={() => setForm({ mode: "edit", row: w })}
                                                                    disabled={!!w.write_guard} title={w.write_guard?.reason} data-testid="warehouse-edit">
                                                                    <i className="ri-pencil-line me-1" />Editar
                                                                </Button>
                                                                <Button size="sm" color="outline-danger" onClick={() => setRemoving(w)}
                                                                    disabled={!!w.write_guard || w.articles_with_stock > 0}
                                                                    title={w.write_guard?.reason ?? (w.articles_with_stock > 0 ? "Só se apaga um armazém sem stock." : undefined)} data-testid="warehouse-delete">
                                                                    <i className="ri-delete-bin-line me-1" />Apagar
                                                                </Button>
                                                            </>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </Table>
                                </div>
                            )}
                        </PageCard>

                        {data && (
                            <PageCard
                                title="Armazém por omissão de cada loja"
                                info="O armazém de onde saem as vendas de cada loja, para o consumo teórico e os dias de cobertura. Vem do PingWin quando um só armazém serve a loja; pode definir outro aqui (fica só na XPLENDOR)."
                            >
                                <div className="table-responsive">
                                    <Table className="align-middle mb-0" data-testid="store-defaults">
                                        <thead className="table-light"><tr><th>Loja</th><th style={{ width: 320 }}>Armazém</th><th>De onde vem</th></tr></thead>
                                        <tbody>
                                            {data.stores.map((s) => (
                                                <tr key={s.store_id}>
                                                    <td className="fw-medium">{s.store_name}</td>
                                                    <td>
                                                        <XSelect
                                                            ariaLabel={`Armazém de ${s.store_name}`}
                                                            small
                                                            searchable={false}
                                                            options={[{ value: "", label: pingwinLabel(s) }, ...active.map((w) => ({ value: w.id, label: w.description ?? w.id }))]}
                                                            value={s.source === "xplendor" ? (s.warehouse_id ?? "") : ""}
                                                            onChange={(v) => onStoreDefault(s.store_id, v)}
                                                            placeholder={pingwinLabel(s)}
                                                            disabled={!data.can_edit || savingStore === s.store_id}
                                                        />
                                                    </td>
                                                    <td className="fs-13">
                                                        {s.source === "pingwin" && <>PingWin: é o único armazém que serve esta loja.</>}
                                                        {s.source === "xplendor" && <>Definido na XPLENDOR.</>}
                                                        {!s.source && <span className="text-warning">Por definir: {s.candidates.length ? `${s.candidates.length} armazéns servem esta loja.` : "nenhum armazém serve esta loja."}</span>}
                                                    </td>
                                                </tr>
                                            ))}
                                            {data.stores.length === 0 && <tr><td colSpan={3} className="text-center text-muted py-3">Sem lojas com vendas.</td></tr>}
                                        </tbody>
                                    </Table>
                                </div>
                            </PageCard>
                        )}

                        {data && data.writes.length > 0 && (
                            <PageCard title="Últimas gravações no PingWin">
                                <ul className="list-unstyled fs-13 mb-0 p-3">
                                    {data.writes.map((w) => (
                                        <li key={w.id} className="py-1 border-bottom">
                                            <span className={`badge bg-${WRITE_STATUS[w.status].color}-subtle text-${WRITE_STATUS[w.status].color} me-2`}>{WRITE_STATUS[w.status].label}</span>
                                            {{ armazem_criar: "Criar", armazem_editar: "Editar", armazem_apagar: "Apagar", limites: "Mínimo e máximo" }[w.kind]}
                                            {` «${w.payload?.description ?? data.warehouses.find((x) => x.id === w.warehouse_id)?.description ?? w.warehouse_id ?? ""}»`} · {fmtWhen(w.created_at)}{w.user ? ` · ${w.user}` : ""}
                                            {w.status !== "ok" && w.error && <div className="text-muted fs-12">{w.error}</div>}
                                        </li>
                                    ))}
                                </ul>
                            </PageCard>
                        )}
                    </Col>
                </Row>

                {form && companyId && data && (
                    <WarehouseForm companyId={companyId} data={data} mode={form.mode} row={form.row} storeName={storeName}
                        onClose={() => setForm(null)} onDone={() => { setForm(null); load(); }} />
                )}
                {removing && companyId && (
                    <DeleteWarehouse companyId={companyId} row={removing} onClose={() => setRemoving(null)} onDone={() => { setRemoving(null); load(); }} />
                )}
            </Container>
        </div>
    );
}

function WarehouseForm({ companyId, data, mode, row, storeName, onClose, onDone }: {
    companyId: number; data: WarehousesResponse; mode: "create" | "edit"; row?: WarehouseRow; storeName: (id: string) => string; onClose: () => void; onDone: () => void;
}) {
    const [name, setName] = useState(row?.description ?? "");
    const [stores, setStores] = useState<string[]>(row ? row.stores.filter((s) => s.served).map((s) => s.store_id) : data.known_stores.map((s) => s.id));
    const [inv, setInv] = useState(String(row?.inv_costmethod ?? 0));
    const [exit, setExit] = useState(String(row?.exit_costmethod ?? 0));
    const [err, setErr] = useState<string | null>(null);
    const { write, setWrite, busy } = useStockWrite(companyId, (w) => {
        if (w.status === "ok") { toast.success(writeMessage(w)); onDone(); } else { setErr(writeMessage(w)); }
    });
    const allowed = data.allowed_names.filter((n) => n !== "0");
    const methodOptions = data.costmethods.map((m) => ({ value: String(m.id), label: m.label }));
    const toggle = (id: string) => setStores((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));

    const submit = async () => {
        setErr(null);
        if (!name.trim()) { setErr("O nome do armazém é obrigatório."); return; }
        try {
            const body = { description: name.trim(), store_ids: stores, inv_costmethod: Number(inv), exit_costmethod: Number(exit) };
            const res: any = mode === "create" ? await createWarehouse(companyId, body) : await updateWarehouse(companyId, row!.id, body);
            setWrite(res?.data?.write ?? null);
        } catch (e: any) {
            setErr(e?.response?.data?.message ?? e?.message ?? "Não foi possível pedir a gravação.");
        }
    };

    return (
        <Modal isOpen centered toggle={() => !busy && onClose()} data-testid="warehouse-form">
            <ModalHeader toggle={() => !busy && onClose()}>{mode === "create" ? "Novo armazém" : `Editar ${row?.description}`}</ModalHeader>
            <ModalBody>
                {allowed.length > 0 && (
                    <Alert color="warning" className="fs-13"><i className="ri-lock-line me-1" />Neste ambiente só se grava no armazém de teste autorizado ({allowed.join(", ")}).</Alert>
                )}
                <p className="text-muted fs-13">Grava no PingWin. Depois a XPLENDOR relê o armazém para confirmar.</p>
                <div className="mb-3">
                    <Label className="form-label" for="wh-name">Nome</Label>
                    <Input id="wh-name" value={name} maxLength={50} onChange={(e) => setName(e.target.value)} disabled={busy} />
                </div>
                <div className="mb-3">
                    <Label className="form-label d-block">Lojas servidas</Label>
                    {data.known_stores.map((s) => (
                        <div className="form-check" key={s.id}>
                            <Input type="checkbox" className="form-check-input" id={`wh-store-${s.id}`} checked={stores.includes(s.id)} onChange={() => toggle(s.id)} disabled={busy} />
                            <Label className="form-check-label" for={`wh-store-${s.id}`}>{storeName(s.id)}</Label>
                        </div>
                    ))}
                </div>
                <div className="row g-3">
                    <div className="col-6">
                        <Label className="form-label">Valorização das existências <InfoTip label="Sobre a valorização" text={COSTMETHOD_TIP} /></Label>
                        <XSelect ariaLabel="Valorização das existências" options={methodOptions} value={inv} onChange={setInv} searchable={false} disabled={busy} />
                    </div>
                    <div className="col-6">
                        <Label className="form-label">Valorização das saídas</Label>
                        <XSelect ariaLabel="Valorização das saídas" options={methodOptions} value={exit} onChange={setExit} searchable={false} disabled={busy} />
                    </div>
                </div>
                {write && (
                    <div className="mt-3 fs-13" role="status">
                        <span className={`badge bg-${WRITE_STATUS[write.status].color}-subtle text-${WRITE_STATUS[write.status].color}`}>{WRITE_STATUS[write.status].label}</span>
                        {busy && <Spinner size="sm" className="ms-2" />}
                    </div>
                )}
                {err && <Alert color="danger" className="mt-3 mb-0 fs-13">{err}</Alert>}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={busy}>Cancelar</Button>
                <Button color="primary" onClick={submit} disabled={busy} data-testid="warehouse-save">
                    {busy ? <><Spinner size="sm" className="me-1" />A gravar no PingWin…</> : <><i className="ri-save-line me-1" />Gravar no PingWin</>}
                </Button>
            </ModalFooter>
        </Modal>
    );
}

function DeleteWarehouse({ companyId, row, onClose, onDone }: { companyId: number; row: WarehouseRow; onClose: () => void; onDone: () => void }) {
    const [step, setStep] = useState<1 | 2>(1);
    const [typed, setTyped] = useState("");
    const [err, setErr] = useState<string | null>(null);
    const { write, setWrite, busy } = useStockWrite(companyId, (w) => {
        if (w.status === "ok") { toast.success("Armazém apagado no PingWin."); onDone(); } else { setErr(writeMessage(w)); }
    });
    const submit = async () => {
        setErr(null);
        try {
            const res: any = await deleteWarehouse(companyId, row.id, typed);
            setWrite(res?.data?.write ?? null);
        } catch (e: any) {
            setErr(e?.response?.data?.message ?? e?.message ?? "Não foi possível pedir para apagar.");
        }
    };

    return (
        <Modal isOpen centered toggle={() => !busy && onClose()} data-testid="warehouse-delete-modal">
            <ModalHeader toggle={() => !busy && onClose()}>Apagar armazém</ModalHeader>
            <ModalBody>
                {step === 1 ? (
                    <>
                        <p>Vai apagar o armazém <strong>{row.description}</strong> no PingWin.</p>
                        <p className="text-muted fs-13 mb-0">
                            Só se apaga um armazém sem stock. As lojas que ele serve deixam de o ter. {row.default_for.length > 0 && <>É o armazém por omissão de {row.default_for.join(", ")}: o consumo teórico dessas lojas fica sem armazém até escolher outro.</>}
                        </p>
                    </>
                ) : (
                    <>
                        <Label className="form-label" for="wh-confirm">Para confirmar, escreva o nome do armazém: <strong>{row.description}</strong></Label>
                        <Input id="wh-confirm" value={typed} onChange={(e) => setTyped(e.target.value)} disabled={busy} autoFocus />
                        {write && (
                            <div className="mt-3 fs-13" role="status">
                                <span className={`badge bg-${WRITE_STATUS[write.status].color}-subtle text-${WRITE_STATUS[write.status].color}`}>{WRITE_STATUS[write.status].label}</span>
                                {busy && <Spinner size="sm" className="ms-2" />}
                            </div>
                        )}
                    </>
                )}
                {err && <Alert color="danger" className="mt-3 mb-0 fs-13">{err}</Alert>}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={busy}>Cancelar</Button>
                {step === 1 ? (
                    <Button color="danger" onClick={() => setStep(2)} data-testid="warehouse-delete-next">Continuar</Button>
                ) : (
                    <Button color="danger" onClick={submit} disabled={busy || typed.trim() !== (row.description ?? "").trim()} data-testid="warehouse-delete-confirm">
                        {busy ? <><Spinner size="sm" className="me-1" />A apagar no PingWin…</> : <>Apagar no PingWin</>}
                    </Button>
                )}
            </ModalFooter>
        </Modal>
    );
}
