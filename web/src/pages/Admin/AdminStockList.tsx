import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Card, CardBody, Col, Container, Row, Badge, Spinner, Input, Table } from "reactstrap";
import Select from "react-select";
import { ToastContainer } from "react-toastify";
import { reactSelectTheme } from "helpers/reactSelectStyles";
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
    document.title = "Administração — Stock global | Xplendor";

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
        () => companies.map((c) => ({ value: c.id, label: `${c.name} (${c.count})` })),
        [companies],
    );
    const statusOptions = useMemo(
        () => CAR_STATUS_OPTIONS.map((s) => ({ value: s, label: CAR_STATUS_META[s].label })),
        [],
    );

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
                <Row className="mb-3">
                    <Col>
                        <h4 className="mb-1"><i className="ri-stack-line text-primary me-2" />Administração — Stock global</h4>
                        <p className="text-muted mb-0">Todos os veículos das empresas ativas da plataforma. Empresas inativas não aparecem.</p>
                    </Col>
                </Row>

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

                <Card>
                    <CardBody>
                        {/* Filtros */}
                        <Row className="g-2 mb-3">
                            <Col md={4}>
                                <Select
                                    styles={reactSelectTheme}
                                    isClearable
                                    placeholder="Todas as empresas"
                                    options={companyOptions}
                                    value={companyOptions.find((o) => o.value === company) ?? null}
                                    onChange={(opt: any) => setCompany(opt?.value ?? null)}
                                />
                            </Col>
                            <Col md={4}>
                                <Select
                                    styles={reactSelectTheme}
                                    isMulti
                                    placeholder="Todos os estados"
                                    options={statusOptions}
                                    value={statusOptions.filter((o) => statuses.includes(o.value))}
                                    onChange={(opts: any) => setStatuses((opts || []).map((o: any) => o.value))}
                                />
                            </Col>
                            <Col md={4}>
                                <Input
                                    type="text"
                                    placeholder="Procurar marca, modelo, matrícula, empresa…"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                            </Col>
                        </Row>

                        {loading ? (
                            <div className="d-flex align-items-center gap-2 text-muted py-4"><Spinner size="sm" /> A carregar…</div>
                        ) : cars.length === 0 ? (
                            <p className="text-muted mb-0 py-3">Sem veículos para os filtros escolhidos.</p>
                        ) : (
                            <>
                                <div className="table-responsive">
                                    <Table className="align-middle table-nowrap mb-0">
                                        <thead className="text-muted table-light">
                                            <tr>
                                                <th>Empresa</th>
                                                <th>Viatura</th>
                                                <th>Estado</th>
                                                <th>Ano / Km</th>
                                                <th className="text-end">Preço</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {cars.map((c) => {
                                                const sm = CAR_STATUS_META[c.status];
                                                return (
                                                    <tr key={c.id}>
                                                        <td><span className="fw-medium">{c.company_name ?? `Empresa #${c.company_id}`}</span></td>
                                                        <td>
                                                            <div className="d-flex align-items-center gap-2">
                                                                {c.thumbnail
                                                                    ? <img src={c.thumbnail} alt="" width={54} height={38} style={{ objectFit: "cover", borderRadius: 6 }} className="flex-shrink-0" />
                                                                    : <span className="flex-shrink-0 d-inline-flex align-items-center justify-content-center text-muted" style={{ width: 54, height: 38, borderRadius: 6, background: "var(--vz-light)" }}><i className="ri-car-line" /></span>}
                                                                <div className="min-w-0">
                                                                    <div className="fw-medium text-truncate">{carTitle(c)}</div>
                                                                    {c.version && <small className="text-muted text-truncate d-block">{c.version}</small>}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td><Badge color={sm?.color ?? "light"} className={sm?.color === "light" ? "text-body" : ""}>{sm?.label ?? c.status}</Badge></td>
                                                        <td className="text-muted">
                                                            {c.registration_year ?? "—"}
                                                            {c.mileage_km != null ? ` · ${c.mileage_km.toLocaleString("pt-PT")} km` : ""}
                                                        </td>
                                                        <td className="text-end">
                                                            {c.hide_price_online
                                                                ? <span className="text-muted fs-12">Sob consulta</span>
                                                                : <span className="fw-semibold">{formatStockEuro(c.promo_price_gross ?? c.price_gross)}</span>}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </Table>
                                </div>

                                {/* Paginação */}
                                <div className="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
                                    <small className="text-muted">{total} veículo{total === 1 ? "" : "s"} · página {page} de {lastPage}</small>
                                    <div className="d-flex gap-2">
                                        <button type="button" className="btn btn-soft-secondary btn-sm" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
                                            <i className="ri-arrow-left-s-line" /> Anterior
                                        </button>
                                        <button type="button" className="btn btn-soft-secondary btn-sm" disabled={page >= lastPage} onClick={() => setPage((p) => Math.min(lastPage, p + 1))}>
                                            Seguinte <i className="ri-arrow-right-s-line" />
                                        </button>
                                    </div>
                                </div>
                            </>
                        )}
                    </CardBody>
                </Card>
            </Container>
        </div>
    );
};

export default AdminStockList;
