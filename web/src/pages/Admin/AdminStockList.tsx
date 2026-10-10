import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Card, CardBody, Col, Container, Row, Badge } from "reactstrap";
import { ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import XSelect, { XMultiSelect } from "Components/Common/Select";
import {
    getAdminStock, getAdminStockSummary, getAdminStockCompanies,
} from "helpers/laravel_helper";
import {
    IAdminStockCar, IAdminStockSummary, IAdminStockCompany, CarStatus,
    CAR_STATUS_META, CAR_STATUS_OPTIONS, formatStockEuro,
} from "common/models/adminStock.model";

const PER_PAGE = 20;
const carTitle = (c: IAdminStockCar) => [c.brand, c.model].filter(Boolean).join(" ") || "Sem nome";

/**
 * XPLENDOR — Consola de STOCK GLOBAL (área /admin, só root). Primeira vista de
 * DADOS transversais: veículos de TODAS as empresas ativas, com a empresa de
 * cada um. Página irmã de Tickets/Orçamentos no mesmo portão super-admin.
 */
const AdminStockList = () => {
    document.title = "Stock global | Xplendor";

    const [cars, setCars] = useState<IAdminStockCar[]>([]);
    const [summary, setSummary] = useState<IAdminStockSummary | null>(null);
    const [companies, setCompanies] = useState<IAdminStockCompany[]>([]);
    const [loading, setLoading] = useState(true);

    const [company, setCompany] = useState<number | null>(null);
    const [statuses, setStatuses] = useState<CarStatus[]>([]);
    const [search, setSearch] = useState("");
    const [debouncedSearch, setDebouncedSearch] = useState("");
    const [page, setPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);
    const [total, setTotal] = useState(0);

    useEffect(() => {
        getAdminStockSummary().then((r: any) => setSummary(r?.data ?? null)).catch(() => setSummary(null));
        getAdminStockCompanies().then((r: any) => setCompanies(r?.data ?? [])).catch(() => setCompanies([]));
    }, []);

    // Debounce da pesquisa (evita um pedido por tecla).
    useEffect(() => {
        const t = setTimeout(() => setDebouncedSearch(search.trim()), 350);
        return () => clearTimeout(t);
    }, [search]);

    // Repõe a página 1 quando um filtro muda.
    useEffect(() => { setPage(1); }, [company, statuses, debouncedSearch]);

    const load = useCallback(() => {
        setLoading(true);
        getAdminStock({
            page,
            per_page: PER_PAGE,
            company_id: company ?? undefined,
            status: statuses.length ? statuses.join(",") : undefined,
            search: debouncedSearch || undefined,
        })
            .then((r: any) => {
                setCars(r?.data?.data ?? []);
                setLastPage(r?.data?.meta?.last_page ?? 1);
                setTotal(r?.data?.meta?.total ?? 0);
            })
            .catch(() => setCars([]))
            .finally(() => setLoading(false));
    }, [page, company, statuses, debouncedSearch]);

    useEffect(() => { load(); }, [load]);

    const companyOptions = useMemo(
        () => [{ value: 0, label: "Todas as empresas" }, ...companies.map((c) => ({ value: c.id, label: `${c.name} (${c.count})` }))],
        [companies],
    );
    const statusOptions = useMemo(
        () => CAR_STATUS_OPTIONS.map((s) => ({ value: s, label: CAR_STATUS_META[s].label })),
        [],
    );

    const cols = useDataColumns<IAdminStockCar>("administracao.stock", [
        { id: "company", header: "Empresa", value: (c) => c.company_name ?? `Empresa #${c.company_id}`, cell: (c) => <span className="fw-medium">{c.company_name ?? `Empresa #${c.company_id}`}</span>, mobile: "subtitle" },
        {
            id: "car", header: "Viatura", value: (c) => carTitle(c), hideable: false, mobile: "title",
            cell: (c) => (
                <div className="d-flex align-items-center gap-2">
                    {c.thumbnail
                        ? <img src={c.thumbnail} alt="" width={54} height={38} style={{ objectFit: "cover", borderRadius: 6 }} className="flex-shrink-0" />
                        : <span className="flex-shrink-0 d-inline-flex align-items-center justify-content-center text-muted" style={{ width: 54, height: 38, borderRadius: 6, background: "var(--vz-light)" }}><i className="ri-car-line" /></span>}
                    <div className="min-w-0">
                        <div className="fw-medium text-truncate">{carTitle(c)}</div>
                        {c.version && <small className="text-muted text-truncate d-block">{c.version}</small>}
                    </div>
                </div>
            ),
        },
        {
            id: "status", header: "Estado",
            cell: (c) => { const sm = CAR_STATUS_META[c.status]; return <Badge color={sm?.color ?? "light"} className={sm?.color === "light" ? "text-body" : ""}>{sm?.label ?? c.status}</Badge>; },
        },
        { id: "year", header: "Ano / Km", cell: (c) => <span className="text-muted">{c.registration_year ?? "—"}{c.mileage_km != null ? ` · ${c.mileage_km.toLocaleString("pt-PT")} km` : ""}</span>, nowrap: true },
        {
            id: "price", header: "Preço", align: "end", nowrap: true,
            cell: (c) => c.hide_price_online ? <span className="text-muted fs-12">Sob consulta</span> : <span className="fw-semibold">{formatStockEuro(c.promo_price_gross ?? c.price_gross)}</span>,
        },
    ] as DTColumn<IAdminStockCar>[]);

    const cards = useMemo(() => ([
        { label: "Veículos na plataforma", value: summary?.total_vehicles ?? 0, icon: "ri-car-line", color: "primary" },
        { label: "Empresas ativas", value: summary?.active_companies ?? 0, icon: "ri-building-line", color: "info" },
        { label: "À venda (ativas)", value: summary?.by_status?.active ?? 0, icon: "ri-price-tag-3-line", color: "success" },
        { label: "Vendidas", value: summary?.by_status?.sold ?? 0, icon: "ri-checkbox-circle-line", color: "secondary" },
    ]), [summary]);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader
                    title="Stock global"
                    breadcrumbs={[{ label: "Administração", to: "/admin" }]}
                    info="Todos os veículos das empresas ativas da plataforma. Empresas inativas não aparecem."
                />

                {/* Cartões do topo — embrião das métricas globais */}
                <Row className="g-3 mb-3">
                    {cards.map((c) => (
                        <Col key={c.label} xs={6} lg={3}>
                            <Card className="mb-0">
                                <CardBody className="d-flex align-items-center gap-3">
                                    <span className="avatar-sm flex-shrink-0">
                                        <span className={`avatar-title bg-${c.color}-subtle text-${c.color} rounded fs-20`}><i className={c.icon} /></span>
                                    </span>
                                    <div className="min-w-0">
                                        <div className="fs-20 fw-semibold">{c.value}</div>
                                        <small className="text-muted">{c.label}</small>
                                    </div>
                                </CardBody>
                            </Card>
                        </Col>
                    ))}
                </Row>

                <PageCard
                    title="Veículos"
                    status={!loading ? <>{total} veículo{total === 1 ? "" : "s"}</> : undefined}
                    loading={loading && cars.length > 0}
                    actions={cols.selector}
                    filters={
                        <RestFilterBar search={search} onSearchChange={setSearch} searchPlaceholder="Procurar marca, modelo, matrícula, empresa…"
                            activeCount={(company ? 1 : 0) + (statuses.length ? 1 : 0) + (search ? 1 : 0)}
                            onClear={() => { setCompany(null); setStatuses([]); setSearch(""); }}>
                            <div style={{ flex: "1 1 220px", minWidth: 0 }}>
                                <XSelect<number> small searchable ariaLabel="Empresa" options={companyOptions} value={company ?? 0} onChange={(v) => setCompany(v || null)} />
                            </div>
                            <div style={{ flex: "1 1 220px", minWidth: 0 }}>
                                <XMultiSelect<CarStatus> small ariaLabel="Estados" placeholder="Todos os estados" options={statusOptions} value={statuses} onChange={setStatuses} />
                            </div>
                        </RestFilterBar>
                    }
                >
                    <DataTable
                        columns={cols}
                        data={cars}
                        rowKey={(c) => c.id}
                        mode="server"
                        loading={loading}
                        caption="Stock global"
                        empty={{ message: "Sem veículos para os filtros escolhidos." }}
                        server={{
                            page, lastPage, total, perPage: PER_PAGE,
                            from: total ? (page - 1) * PER_PAGE + 1 : 0,
                            to: Math.min(total, page * PER_PAGE),
                            onPageChange: setPage,
                        }}
                    />
                </PageCard>
            </Container>
        </div>
    );
};

export default AdminStockList;
