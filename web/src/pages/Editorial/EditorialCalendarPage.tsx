import { useCallback, useEffect, useMemo, useState } from "react";
import {
    Card, CardBody, Container, Row, Col, Spinner, Modal, ModalHeader, ModalBody, ModalFooter, Button,
    Form, FormGroup, Label, Input,
} from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import {
    getEditorialCalendar, setEditorialSector, openEditorialMonth, closeEditorialMonth,
    hideEditorialAnchor, showEditorialAnchor, createEditorialOwnAnchor, deleteEditorialOwnAnchor,
} from "helpers/laravel_helper";
import SectorChooser from "./SectorChooser";

/**
 * XPLENDOR — Linha Editorial (B1+B2+B3a): escolher ramo + calendário herdado (12 meses) +
 * máquina de estados dos meses + TRABALHO nos meses abertos: esconder/mostrar âncoras
 * herdadas (por ocorrência) e criar/apagar âncoras próprias. Mês bloqueado = só vista
 * esbatida, sem ações (o cadeado bloqueia o trabalho, não a leitura). Troca de ramo é B3b.
 */

type BaseItem = { title: string; origin: string; rule_type: string; anchor_id: number; owned: boolean; hidden: boolean; occ_year: number; month_key: string };
type DayItem = BaseItem & { type: "day"; date: string };
type RangeItem = BaseItem & { type: "range"; start: string; end: string };
type Item = DayItem | RangeItem;

type MonthState = {
    year: number; month: number; month_key: string;
    state: "open" | "closed"; is_current: boolean;
    can_open: boolean; can_close: boolean; closes_also: string[];
};

const MONTHS_PT = ["Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];
const WEEKDAYS_PT = ["Domingo", "Segunda", "Terça", "Quarta", "Quinta", "Sexta", "Sábado"];
const ORDINALS = [{ v: 1, l: "1.º" }, { v: 2, l: "2.º" }, { v: 3, l: "3.º" }, { v: 4, l: "4.º" }, { v: 5, l: "5.º" }, { v: -1, l: "Último" }];
const monthLabel = (key: string) => { const [y, m] = key.split("-"); return `${MONTHS_PT[Number(m) - 1]} ${y}`; };
const dayOf = (iso: string) => Number(iso.split("-")[2]);
const fmtDay = (iso: string) => { const [, m, d] = iso.split("-"); return `${d}/${m}`; };

type CreateForm = {
    title: string; rule_type: string;
    month: number; day: number;
    ordinal: number; weekday: number;
    start_month: number; start_day: number; end_month: number; end_day: number;
    easter_offset: number;
};
const emptyForm = (month: number): CreateForm => ({
    title: "", rule_type: "fixa",
    month, day: 1,
    ordinal: 1, weekday: 0,
    start_month: month, start_day: 1, end_month: month, end_day: 28,
    easter_offset: 0,
});

export default function EditorialCalendarPage() {
    document.title = "Linha Editorial | Xplendor";

    const companyId = useMemo(() => {
        const a = sessionStorage.getItem("authUser");
        try { return a ? Number(JSON.parse(a).company_id || 0) : 0; } catch { return 0; }
    }, []);

    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [hasSector, setHasSector] = useState<boolean | null>(null);
    const [sectorName, setSectorName] = useState<string>("");
    const [range, setRange] = useState<{ from: string; to: string } | null>(null);
    const [items, setItems] = useState<Item[]>([]);
    const [months, setMonths] = useState<MonthState[]>([]);
    const [acting, setActing] = useState<string | null>(null);        // month_key de um mês em transição (abrir/fechar)
    const [working, setWorking] = useState(false);                    // ação de âncora a decorrer
    const [cascade, setCascade] = useState<MonthState | null>(null);  // alvo do modal de fecho em cascata

    // Modal de criar âncora própria (associado ao mês aberto onde o botão foi clicado).
    const [createFor, setCreateFor] = useState<MonthState | null>(null);
    const [form, setForm] = useState<CreateForm>(emptyForm(1));

    const applyCalendar = useCallback((d: any) => {
        setHasSector(!!d.has_sector);
        if (d.has_sector) {
            setSectorName(d.sector?.name ?? "");
            setRange({ from: d.from, to: d.to });
            setItems(d.items ?? []);
            setMonths(d.months ?? []);
        }
    }, []);

    const load = useCallback(async () => {
        if (!companyId) { setLoading(false); return; }
        setLoading(true);
        try {
            const r: any = await getEditorialCalendar(companyId);
            applyCalendar(r?.data ?? {});
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar a linha editorial.");
        } finally {
            setLoading(false);
        }
    }, [companyId, applyCalendar]);

    useEffect(() => { load(); }, [load]);

    const chooseSector = async (sectorId: number) => {
        setSaving(true);
        try {
            await setEditorialSector(companyId, sectorId);
            toast.success("Ramo definido.");
            await load();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível definir o ramo.");
        } finally {
            setSaving(false);
        }
    };

    const itemsByMonth = useMemo(() => {
        const by: Record<string, Item[]> = {};
        for (const it of items) (by[it.month_key] ??= []).push(it);
        return by;
    }, [items]);

    // ── Máquina de estados dos meses (B2) ──
    const doOpen = async (mo: MonthState) => {
        setActing(mo.month_key);
        try {
            const r: any = await openEditorialMonth(companyId, mo.year, mo.month);
            setMonths(r?.data?.months ?? []);
            toast.success(`${monthLabel(mo.month_key)} aberto.`);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível abrir o mês.");
            await load();
        } finally { setActing(null); }
    };

    const performClose = async (mo: MonthState) => {
        setActing(mo.month_key);
        setCascade(null);
        try {
            const r: any = await closeEditorialMonth(companyId, mo.year, mo.month);
            setMonths(r?.data?.months ?? []);
            toast.success(`${monthLabel(mo.month_key)} fechado.`);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível fechar o mês.");
            await load();
        } finally { setActing(null); }
    };

    const doClose = (mo: MonthState) => {
        if (mo.closes_also.length > 0) setCascade(mo);
        else performClose(mo);
    };

    // ── Trabalho nas âncoras (B3a) — todas devolvem o calendar atualizado ──
    const runAnchor = async (fn: () => Promise<any>, okMsg: string) => {
        setWorking(true);
        try {
            const r: any = await fn();
            applyCalendar(r?.data ?? {});
            toast.success(okMsg);
        } catch (e: any) {
            toast.error(e?.message ?? "Operação não permitida.");
            await load();
        } finally { setWorking(false); }
    };

    const onHide = (it: Item) => runAnchor(() => hideEditorialAnchor(companyId, it.anchor_id, it.occ_year), "Âncora escondida.");
    const onShow = (it: Item) => runAnchor(() => showEditorialAnchor(companyId, it.anchor_id, it.occ_year), "Âncora mostrada.");
    const onDelete = (it: Item) => runAnchor(() => deleteEditorialOwnAnchor(companyId, it.anchor_id), "Âncora própria apagada.");

    const openCreate = (mo: MonthState) => { setForm(emptyForm(mo.month)); setCreateFor(mo); };

    const submitCreate = async () => {
        const f = form;
        const payload: any = { title: f.title.trim(), rule_type: f.rule_type };
        if (f.rule_type === "fixa") { payload.month = f.month; payload.day = f.day; }
        else if (f.rule_type === "nth_weekday") { payload.month = f.month; payload.ordinal = f.ordinal; payload.weekday = f.weekday; }
        else if (f.rule_type === "periodo") { payload.start_month = f.start_month; payload.start_day = f.start_day; payload.end_month = f.end_month; payload.end_day = f.end_day; }
        else if (f.rule_type === "relativa_pascoa") { payload.easter_offset = f.easter_offset; }

        if (!payload.title) { toast.error("Dá um título à âncora."); return; }

        setWorking(true);
        try {
            const r: any = await createEditorialOwnAnchor(companyId, payload);
            applyCalendar(r?.data ?? {});
            toast.success("Âncora própria criada.");
            setCreateFor(null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível criar a âncora.");
        } finally { setWorking(false); }
    };

    const setF = (patch: Partial<CreateForm>) => setForm((p) => ({ ...p, ...patch }));

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <div className="page-title-box">
                    <h4 className="mb-sm-0">Linha Editorial</h4>
                    {hasSector && (
                        <small className="text-muted">
                            Ramo: <strong>{sectorName}</strong>
                            {range && <> · próximos 12 meses ({fmtDay(range.from)} a {fmtDay(range.to)})</>}
                        </small>
                    )}
                </div>

                {loading ? (
                    <div className="text-center py-5"><Spinner color="primary" /></div>
                ) : hasSector === false ? (
                    <Row className="justify-content-center"><Col xl={8}>
                        <SectorChooser companyId={companyId} busy={saving} onChoose={chooseSector} />
                    </Col></Row>
                ) : (
                    <Row className="g-3">
                        {months.map((mo) => {
                            const monthItems = itemsByMonth[mo.month_key] ?? [];
                            const isOpen = mo.state === "open";
                            const busy = acting === mo.month_key;
                            return (
                                <Col key={mo.month_key} md={6} xl={4}>
                                    <Card className={`h-100 mb-0 ${isOpen ? "border-success border-opacity-25" : ""}`}>
                                        <CardBody className="d-flex flex-column">
                                            <div className="d-flex align-items-center justify-content-between mb-3">
                                                <h6 className="text-uppercase text-muted fs-12 mb-0" style={{ letterSpacing: "0.05em" }}>
                                                    {monthLabel(mo.month_key)}
                                                    {mo.is_current && <span className="badge bg-primary-subtle text-primary ms-2">este mês</span>}
                                                </h6>
                                                {isOpen ? (
                                                    <span className="badge bg-success-subtle text-success"><i className="ri-lock-unlock-line align-middle me-1" />Aberto</span>
                                                ) : (
                                                    <span className="badge bg-body-secondary text-muted"><i className="ri-lock-2-line align-middle me-1" />Bloqueado</span>
                                                )}
                                            </div>

                                            {monthItems.length === 0 ? (
                                                <p className="text-muted fst-italic mb-3" style={{ opacity: isOpen ? 1 : 0.6 }}>Sem datas.</p>
                                            ) : (
                                                <ul className="list-unstyled vstack gap-2 mb-3 flex-grow-1">
                                                    {monthItems.map((it, i) => {
                                                        const dimmed = !isOpen || it.hidden;
                                                        return (
                                                            <li key={`${it.owned ? "o" : "h"}-${it.anchor_id}-${i}`} className="d-flex align-items-start gap-2" style={{ opacity: dimmed ? 0.5 : 1 }}>
                                                                <span className="badge bg-primary-subtle text-primary flex-shrink-0" style={{ minWidth: 44 }}>
                                                                    {it.type === "day" ? dayOf(it.date) : `${dayOf(it.start)}–${dayOf(it.end)}`}
                                                                </span>
                                                                <span className="flex-grow-1">
                                                                    <span className={it.hidden ? "text-decoration-line-through" : ""}>{it.title}</span>
                                                                    {it.owned
                                                                        ? <span className="badge bg-success-subtle text-success ms-2">própria</span>
                                                                        : <span className="badge bg-secondary-subtle text-secondary ms-2">herdada</span>}
                                                                    {it.type === "range" && <span className="badge bg-info-subtle text-info ms-2">período</span>}
                                                                    {it.hidden && <span className="badge bg-warning-subtle text-warning ms-2">escondida</span>}
                                                                </span>
                                                                {/* Ações só em mês ABERTO. */}
                                                                {isOpen && (
                                                                    <span className="flex-shrink-0">
                                                                        {it.owned ? (
                                                                            <button type="button" className="btn btn-sm btn-ghost-danger p-0 px-1" title="Apagar" disabled={working} onClick={() => onDelete(it)}>
                                                                                <i className="ri-delete-bin-line" />
                                                                            </button>
                                                                        ) : it.hidden ? (
                                                                            <button type="button" className="btn btn-sm btn-ghost-secondary p-0 px-1" title="Mostrar" disabled={working} onClick={() => onShow(it)}>
                                                                                <i className="ri-eye-line" />
                                                                            </button>
                                                                        ) : (
                                                                            <button type="button" className="btn btn-sm btn-ghost-secondary p-0 px-1" title="Esconder" disabled={working} onClick={() => onHide(it)}>
                                                                                <i className="ri-eye-off-line" />
                                                                            </button>
                                                                        )}
                                                                    </span>
                                                                )}
                                                            </li>
                                                        );
                                                    })}
                                                </ul>
                                            )}

                                            {/* Rodapé: criar própria (só aberto) + abrir/fechar mês. */}
                                            <div className="border-top pt-2 mt-auto d-flex justify-content-between align-items-center">
                                                {isOpen ? (
                                                    <Button color="soft-primary" size="sm" disabled={working} onClick={() => openCreate(mo)}>
                                                        <i className="ri-add-line me-1" />Âncora própria
                                                    </Button>
                                                ) : <span />}

                                                {mo.can_open ? (
                                                    <Button color="success" size="sm" disabled={busy} onClick={() => doOpen(mo)}>
                                                        {busy ? <Spinner size="sm" /> : <><i className="ri-lock-unlock-line me-1" />Abrir</>}
                                                    </Button>
                                                ) : mo.can_close ? (
                                                    <Button color="light" size="sm" disabled={busy} onClick={() => doClose(mo)}>
                                                        {busy ? <Spinner size="sm" /> : <><i className="ri-lock-2-line me-1" />Fechar</>}
                                                    </Button>
                                                ) : (
                                                    <span className="text-muted fs-12"><i className="ri-lock-2-line me-1" />Abre o mês anterior</span>
                                                )}
                                            </div>
                                        </CardBody>
                                    </Card>
                                </Col>
                            );
                        })}
                    </Row>
                )}
            </Container>

            {/* Modal — fecho em cascata (B2). */}
            <Modal isOpen={!!cascade} toggle={() => setCascade(null)} centered>
                <ModalHeader toggle={() => setCascade(null)}>Fechar em cascata</ModalHeader>
                <ModalBody>
                    {cascade && (
                        <>
                            <p className="mb-2">Fechar <strong>{monthLabel(cascade.month_key)}</strong> vai fechar também os meses seguintes:</p>
                            <ul className="mb-2">{cascade.closes_also.map((k) => <li key={k}>{monthLabel(k)}</li>)}</ul>
                            <p className="text-muted mb-0 fs-13">O trabalho fica guardado e reaparece quando voltares a abrir.</p>
                        </>
                    )}
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setCascade(null)}>Cancelar</Button>
                    <Button color="danger" onClick={() => cascade && performClose(cascade)}><i className="ri-lock-2-line me-1" />Fechar estes meses</Button>
                </ModalFooter>
            </Modal>

            {/* Modal — criar âncora própria (B3a). */}
            <Modal isOpen={!!createFor} toggle={() => setCreateFor(null)} centered>
                <ModalHeader toggle={() => setCreateFor(null)}>
                    Nova âncora própria{createFor && <> — {monthLabel(createFor.month_key)}</>}
                </ModalHeader>
                <ModalBody>
                    <Form onSubmit={(e) => { e.preventDefault(); submitCreate(); }}>
                        <FormGroup>
                            <Label>Título</Label>
                            <Input value={form.title} onChange={(e) => setF({ title: e.target.value })} placeholder="Ex.: Aniversário da Empresa" autoFocus />
                        </FormGroup>
                        <FormGroup>
                            <Label>Tipo de regra</Label>
                            <Input type="select" value={form.rule_type} onChange={(e) => setF({ rule_type: e.target.value })}>
                                <option value="fixa">Data fixa (dia/mês)</option>
                                <option value="nth_weekday">N-ésimo dia da semana</option>
                                <option value="periodo">Período (intervalo)</option>
                                <option value="relativa_pascoa">Relativa à Páscoa</option>
                            </Input>
                        </FormGroup>

                        {form.rule_type === "fixa" && (
                            <Row className="g-2">
                                <Col xs={7}><FormGroup><Label>Mês</Label>
                                    <Input type="select" value={form.month} onChange={(e) => setF({ month: Number(e.target.value) })}>
                                        {MONTHS_PT.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}
                                    </Input></FormGroup></Col>
                                <Col xs={5}><FormGroup><Label>Dia</Label>
                                    <Input type="number" min={1} max={31} value={form.day} onChange={(e) => setF({ day: Number(e.target.value) })} /></FormGroup></Col>
                            </Row>
                        )}

                        {form.rule_type === "nth_weekday" && (
                            <Row className="g-2">
                                <Col xs={4}><FormGroup><Label>Ordinal</Label>
                                    <Input type="select" value={form.ordinal} onChange={(e) => setF({ ordinal: Number(e.target.value) })}>
                                        {ORDINALS.map((o) => <option key={o.v} value={o.v}>{o.l}</option>)}
                                    </Input></FormGroup></Col>
                                <Col xs={4}><FormGroup><Label>Dia</Label>
                                    <Input type="select" value={form.weekday} onChange={(e) => setF({ weekday: Number(e.target.value) })}>
                                        {WEEKDAYS_PT.map((w, i) => <option key={i} value={i}>{w}</option>)}
                                    </Input></FormGroup></Col>
                                <Col xs={4}><FormGroup><Label>Mês</Label>
                                    <Input type="select" value={form.month} onChange={(e) => setF({ month: Number(e.target.value) })}>
                                        {MONTHS_PT.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}
                                    </Input></FormGroup></Col>
                            </Row>
                        )}

                        {form.rule_type === "periodo" && (
                            <>
                                <Row className="g-2">
                                    <Col xs={7}><FormGroup><Label>Mês (início)</Label>
                                        <Input type="select" value={form.start_month} onChange={(e) => setF({ start_month: Number(e.target.value) })}>
                                            {MONTHS_PT.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}
                                        </Input></FormGroup></Col>
                                    <Col xs={5}><FormGroup><Label>Dia (início)</Label>
                                        <Input type="number" min={1} max={31} value={form.start_day} onChange={(e) => setF({ start_day: Number(e.target.value) })} /></FormGroup></Col>
                                </Row>
                                <Row className="g-2">
                                    <Col xs={7}><FormGroup><Label>Mês (fim)</Label>
                                        <Input type="select" value={form.end_month} onChange={(e) => setF({ end_month: Number(e.target.value) })}>
                                            {MONTHS_PT.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}
                                        </Input></FormGroup></Col>
                                    <Col xs={5}><FormGroup><Label>Dia (fim)</Label>
                                        <Input type="number" min={1} max={31} value={form.end_day} onChange={(e) => setF({ end_day: Number(e.target.value) })} /></FormGroup></Col>
                                </Row>
                            </>
                        )}

                        {form.rule_type === "relativa_pascoa" && (
                            <FormGroup>
                                <Label>Dias face à Páscoa (± inteiro)</Label>
                                <Input type="number" value={form.easter_offset} onChange={(e) => setF({ easter_offset: Number(e.target.value) })} />
                                <small className="text-muted">Ex.: −2 = Sexta-feira Santa · +60 = Corpo de Deus · 0 = Páscoa.</small>
                            </FormGroup>
                        )}
                    </Form>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setCreateFor(null)}>Cancelar</Button>
                    <Button color="primary" disabled={working} onClick={submitCreate}>
                        {working ? <Spinner size="sm" /> : <><i className="ri-check-line me-1" />Criar</>}
                    </Button>
                </ModalFooter>
            </Modal>
        </div>
    );
}
