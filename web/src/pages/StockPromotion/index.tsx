import { useCallback, useEffect, useMemo, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { Link } from "react-router-dom";
import { ToastContainer } from "react-toastify";
import { Container, Input, Label } from "reactstrap";
import { createSelector } from "reselect";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import XSelect, { XMultiSelect } from "Components/Common/Select";
import { listPromotionCandidates, getPromotionSummary } from "../../helpers/stockPromotion_helper";
import { getCompaniesPaginate } from "slices/companies/thunk";
import {
    labelOf,
    PRICE_SIGNAL_LABELS,
    VEHICLE_TYPE_LABELS,
    CAR_STATUS_LABELS,
} from "../../helpers/labels";
import { formatIpsBadge } from "../../helpers/ips";
import { showApiErrorToast } from "../../helpers/error_helper";
import type {
    PromotionCandidate,
    PromotionCandidatesPage,
    PromotionSummary,
    PromotionVehicleStatus,
    PromotionVehicleType,
    ListPromotionCandidatesParams,
    MarketPriceSignal,
} from "../../types/api";
import StarToggle from "./components/StarToggle";
import MarketChip from "./components/MarketChip";
import PromotionMobileCard from "./components/PromotionMobileCard";
import CarThumbnail from "Components/Common/CarThumbnail";
import { getWorkingCompanyId } from "helpers/workingCompany";

const LABEL_STYLE: React.CSSProperties = { letterSpacing: "0.05em" };
const FILTER_LABEL = "text-muted fw-semibold fs-11 text-uppercase mb-1";

const VEHICLE_TYPE_OPTIONS: { value: PromotionVehicleType; label: string }[] = [
    { value: "car",        label: "Carro" },
    { value: "motorhome",  label: "Autocaravana" },
    { value: "caravan",    label: "Caravana" },
    { value: "motorcycle", label: "Mota" },
];

const STATUS_OPTIONS: { value: PromotionVehicleStatus; label: string }[] = [
    { value: "active",         label: CAR_STATUS_LABELS.active },
    { value: "available_soon", label: CAR_STATUS_LABELS.available_soon },
    { value: "reserved",       label: CAR_STATUS_LABELS.reserved },
];

const PRICE_SIGNAL_OPTIONS: { value: MarketPriceSignal; label: string }[] = [
    { value: "overpriced",    label: PRICE_SIGNAL_LABELS.overpriced },
    { value: "slightly_high", label: PRICE_SIGNAL_LABELS.slightly_high },
    { value: "fair",          label: PRICE_SIGNAL_LABELS.fair },
    { value: "competitive",   label: PRICE_SIGNAL_LABELS.competitive },
];

// Ordenação no servidor (as mesmas chaves do antigo "Ordenar por"): coluna → sort_by.
const SORT_COLUMN: Record<string, string> = { days_in_stock: "days", price: "price", views: "views", leads: "leads", ips: "ips" };
const DEFAULT_SORT = { by: "days_in_stock" as const, dir: "desc" as const };

const formatEuro = (v: number | null): string => {
    if (v === null) return "—";
    return new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    }).format(v);
};

const selectCompanies = createSelector(
    (state: any) => state.Company?.data?.companies ?? [],
    (companies) => companies,
);

const StockPromotionPage = () => {
    const dispatch: any = useDispatch();

    // ── Auth / company resolution ─────────────────────────────────────────
    const [userRole, setUserRole] = useState<string | null>(null);
    const [userCompanyId, setUserCompanyId] = useState<number | null>(null);
    const [selectedCompanyId, setSelectedCompanyId] = useState<number | null>(null);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;
        const obj = JSON.parse(authUser);
        setUserRole(obj.role);
        setUserCompanyId(getWorkingCompanyId());
        setSelectedCompanyId(getWorkingCompanyId());
    }, []);

    const isRoot = userRole === "root";
    const companies = useSelector(selectCompanies);

    useEffect(() => {
        if (isRoot) dispatch(getCompaniesPaginate({ page: 1, perPage: 100 }));
    }, [dispatch, isRoot]);

    const companyOptions = useMemo(
        () => companies.map((c: any) => ({
            value: c.id,
            label: c.trade_name || c.fiscal_name || `Empresa ${c.id}`,
        })),
        [companies],
    );

    // ── Filters ───────────────────────────────────────────────────────────
    const [vehicleType, setVehicleType] = useState<PromotionVehicleType | null>(null);
    const [statuses, setStatuses] = useState<PromotionVehicleStatus[]>([]);
    const [minPrice, setMinPrice] = useState<string>("");
    const [maxPrice, setMaxPrice] = useState<string>("");
    const [minDays, setMinDays] = useState<string>("");
    const [maxDays, setMaxDays] = useState<string>("");
    const [priceSignals, setPriceSignals] = useState<MarketPriceSignal[]>([]);
    const [onlyMarked, setOnlyMarked] = useState(false);
    const [sortBy, setSortBy] = useState<ListPromotionCandidatesParams["sort_by"]>(DEFAULT_SORT.by);
    const [sortDir, setSortDir] = useState<"asc" | "desc">(DEFAULT_SORT.dir);

    const activeFilterCount = [
        vehicleType,
        statuses.length > 0,
        minPrice,
        maxPrice,
        minDays,
        maxDays,
        priceSignals.length > 0,
        onlyMarked,
    ].filter(Boolean).length;

    // ── Data ──────────────────────────────────────────────────────────────
    const [pagination, setPagination] = useState({ pageIndex: 0, pageSize: 10 });
    const [page, setPage] = useState<PromotionCandidatesPage | null>(null);
    const [summary, setSummary] = useState<PromotionSummary | null>(null);
    const [loading, setLoading] = useState(false);

    const fetchPage = useCallback(async () => {
        if (selectedCompanyId === null) return;
        setLoading(true);
        try {
            const result = await listPromotionCandidates(selectedCompanyId, {
                page: pagination.pageIndex + 1,
                per_page: pagination.pageSize,
                vehicle_type: vehicleType ?? undefined,
                status: statuses.length > 0 ? statuses : undefined,
                min_price: minPrice ? Number(minPrice) : undefined,
                max_price: maxPrice ? Number(maxPrice) : undefined,
                min_days_in_stock: minDays ? Number(minDays) : undefined,
                max_days_in_stock: maxDays ? Number(maxDays) : undefined,
                price_signal: priceSignals.length > 0 ? priceSignals : undefined,
                only_marked: onlyMarked ? true : undefined,
                sort_by: sortBy,
                sort_dir: sortDir,
            });
            setPage(result);
        } catch (err) {
            showApiErrorToast(err, "Não foi possível carregar o relatório.");
        } finally {
            setLoading(false);
        }
    }, [
        selectedCompanyId, pagination, vehicleType, statuses, minPrice, maxPrice,
        minDays, maxDays, priceSignals, onlyMarked, sortBy, sortDir,
    ]);

    const fetchSummary = useCallback(async () => {
        if (selectedCompanyId === null) return;
        try {
            const s = await getPromotionSummary(selectedCompanyId);
            setSummary(s);
        } catch {
            setSummary(null);
        }
    }, [selectedCompanyId]);

    useEffect(() => { fetchPage(); }, [fetchPage]);
    useEffect(() => { fetchSummary(); }, [fetchSummary]);

    const handlePriorityChanged = useCallback(
        (carId: number, next: PromotionCandidate["promotion"]) => {
            setPage((prev) => {
                if (!prev) return prev;
                return {
                    ...prev,
                    data: prev.data.map((c) =>
                        c.id === carId ? { ...c, promotion: next } : c
                    ),
                };
            });
            fetchSummary();
        },
        [fetchSummary],
    );

    const handleClearFilters = () => {
        setVehicleType(null);
        setStatuses([]);
        setMinPrice("");
        setMaxPrice("");
        setMinDays("");
        setMaxDays("");
        setPriceSignals([]);
        setOnlyMarked(false);
    };

    // ── Filtros do cartão (RestFilterBar: numa linha no computador; "Filtros" no telemóvel) ──
    const field = (flex = "1 1 180px") => ({ flex, minWidth: 0 });
    const filterFields = (
        <>
            <div style={field("1 1 160px")}>
                <Label className={FILTER_LABEL} style={LABEL_STYLE}>Tipo de viatura</Label>
                <XSelect
                    small
                    ariaLabel="Tipo de viatura"
                    options={[{ value: "", label: "Todos os tipos" }, ...VEHICLE_TYPE_OPTIONS]}
                    value={vehicleType ?? ""}
                    onChange={(v) => setVehicleType((v || null) as PromotionVehicleType | null)}
                    searchable={false}
                />
            </div>
            <div style={field()}>
                <Label className={FILTER_LABEL} style={LABEL_STYLE}>Estado</Label>
                <XMultiSelect small ariaLabel="Estado" placeholder="Todos os estados" options={STATUS_OPTIONS} value={statuses} onChange={setStatuses} />
            </div>
            <div style={field()}>
                <Label className={FILTER_LABEL} style={LABEL_STYLE}>Posição vs mercado</Label>
                <XMultiSelect small ariaLabel="Posição vs mercado" placeholder="Qualquer posição" options={PRICE_SIGNAL_OPTIONS} value={priceSignals} onChange={setPriceSignals} />
            </div>
            <div style={field("1 1 170px")}>
                <Label className={FILTER_LABEL} style={LABEL_STYLE}>Dias em stock</Label>
                <div className="d-flex gap-2 align-items-center">
                    <Input type="number" min={0} placeholder="De" value={minDays} onChange={(e) => setMinDays(e.target.value)} bsSize="sm" aria-label="Dias em stock, de" />
                    <span className="fw-semibold text-muted fs-12">até</span>
                    <Input type="number" min={0} placeholder="Até" value={maxDays} onChange={(e) => setMaxDays(e.target.value)} bsSize="sm" aria-label="Dias em stock, até" />
                </div>
            </div>
            <div style={field("1 1 200px")}>
                <Label className={FILTER_LABEL} style={LABEL_STYLE}>Preço (€)</Label>
                <div className="d-flex gap-2 align-items-center">
                    <Input type="number" min={0} placeholder="Preço de" value={minPrice} onChange={(e) => setMinPrice(e.target.value)} bsSize="sm" aria-label="Preço de" />
                    <span className="fw-semibold text-muted fs-12">até</span>
                    <Input type="number" min={0} placeholder="Preço até" value={maxPrice} onChange={(e) => setMaxPrice(e.target.value)} bsSize="sm" aria-label="Preço até" />
                </div>
            </div>
            <div className="form-check mb-1 align-self-center" style={{ flex: "0 0 auto" }}>
                <Input type="checkbox" id="only-marked" className="form-check-input" checked={onlyMarked} onChange={(e) => setOnlyMarked(e.target.checked)} />
                <Label for="only-marked" className="form-check-label fs-12">Só com prioridade marcada</Label>
            </div>
        </>
    );

    // ── Tabela: coluna "Viatura" espelha o layout da CarList ──────────────
    const columnDefs: DTColumn<PromotionCandidate>[] = [
        {
            id: "vehicle",
            header: "Viatura",
            hideable: false,
            cell: (r) => {
                const title = [r.brand?.name, r.model?.name].filter(Boolean).join(" ");
                const isHabitation = r.vehicle_type === "motorhome" || r.vehicle_type === "caravan";
                const taxonomy = isHabitation
                    ? r.category?.name
                    : (r.segment && r.segment !== r.vehicle_type ? r.segment : null);
                const metaLine = [
                    labelOf(r.vehicle_type, VEHICLE_TYPE_LABELS),
                    taxonomy,
                    r.engine_brand ? `motor ${r.engine_brand}` : null,
                    r.registration_year ? String(r.registration_year) : null,
                ].filter(Boolean).join(" · ");

                return (
                    <div className="d-flex align-items-center">
                        <div className="flex-shrink-0 me-3">
                            <CarThumbnail src={r.thumbnail} width={150} variant="row" />
                        </div>
                        <div className="flex-grow-1" style={{ minWidth: 0 }}>
                            <div className="d-flex align-items-center flex-wrap gap-2 mb-1">
                                <Link to={`/cars/${r.id}/ficha`} className="text-decoration-none">
                                    <h5 className="fs-14 mb-0 fw-semibold text-body text-truncate">
                                        {title || "Sem nome"}
                                    </h5>
                                </Link>
                                {r.is_stale && (
                                    <span
                                        className="badge rounded-pill px-3 py-2 fs-11 bg-warning-subtle text-warning"
                                        title="Parada há muito tempo"
                                    >
                                        <i className="ri-time-line me-1" />
                                        Parada há muito
                                    </span>
                                )}
                            </div>
                            {r.version && (
                                <p className="text-muted mb-1 fs-13">
                                    <span className="fw-medium">{r.version}</span>
                                </p>
                            )}
                            {metaLine && (
                                <p className="text-muted mb-0 fs-12">{metaLine}</p>
                            )}
                        </div>
                    </div>
                );
            },
        },
        {
            id: "price",
            header: "Preço",
            sortKey: "price",
            value: (r) => r.price.effective,
            cell: (r) => (
                <div>
                    <div className="fw-semibold text-body">{formatEuro(r.price.effective)}</div>
                    {r.price.has_promo && (
                        <div className="text-muted fs-12 text-decoration-line-through">
                            {formatEuro(r.price.gross)}
                        </div>
                    )}
                    {r.price.hide_online && (
                        <div className="text-muted fs-12">Sob consulta</div>
                    )}
                </div>
            ),
        },
        { id: "market", header: "Mercado", cell: (r) => <MarketChip market={r.market} rowId={r.id} /> },
        {
            id: "ips",
            header: "IPS",
            sortKey: "ips",
            value: (r) => r.ips?.score ?? undefined,
            cell: (r) => {
                const badge = formatIpsBadge(r.ips?.score, r.ips?.classification);
                return <span className={`badge ${badge.className} fw-normal`}>{badge.shortLabel}</span>;
            },
        },
        {
            id: "days",
            header: "Em stock",
            sortKey: "days_in_stock",
            value: (r) => r.days_in_stock,
            nowrap: true,
            cell: (r) => (
                <span className="fw-semibold text-body">
                    {r.days_in_stock} <span className="fw-normal text-muted fs-12">{r.days_in_stock === 1 ? "dia" : "dias"}</span>
                </span>
            ),
        },
        {
            id: "views",
            header: "Views",
            sortKey: "views",
            value: (r) => r.engagement.views_count,
            cell: (r) => <span className="fw-semibold text-body">{r.engagement.views_count}</span>,
        },
        {
            id: "leads",
            header: "Leads",
            sortKey: "leads",
            value: (r) => r.engagement.leads_count,
            cell: (r) => <span className={`fw-semibold ${r.engagement.leads_count > 0 ? "text-success" : "text-body"}`}>{r.engagement.leads_count}</span>,
        },
        {
            id: "promotion",
            header: "Promover",
            cell: (r) => (
                <div className="d-flex flex-column align-items-start gap-1">
                    {selectedCompanyId !== null && (
                        <StarToggle
                            companyId={selectedCompanyId}
                            carId={r.id}
                            priority={r.promotion}
                            onChange={(next) => handlePriorityChanged(r.id, next)}
                            size="md"
                        />
                    )}
                    {r.promotion && (
                        <div className="text-muted fs-12" style={{ lineHeight: 1.3 }}>
                            <div>{r.promotion.marked_by?.name ?? "—"}</div>
                            {r.promotion.marked_at && (
                                <div>{new Date(r.promotion.marked_at).toLocaleDateString("pt-PT")}</div>
                            )}
                        </div>
                    )}
                </div>
            ),
        },
    ];
    const cols = useDataColumns("comercial.candidatas-promocao", columnDefs);

    const renderMobileCard = useCallback((rowData: PromotionCandidate) => {
        if (selectedCompanyId === null) return null;
        return (
            <PromotionMobileCard
                candidate={rowData}
                companyId={selectedCompanyId}
                onPriorityChanged={handlePriorityChanged}
            />
        );
    }, [selectedCompanyId, handlePriorityChanged]);

    const total = page?.meta?.total ?? summary?.visible_total ?? 0;
    const meta: any = page?.meta ?? {};

    document.title = "Candidatas a promoção | Xplendor";

    return (
        <div className="page-content">
            <ToastContainer closeButton={false} limit={1} />
            <Container fluid>
                <PageHeader
                    title="Candidatas a promoção"
                    breadcrumbs={[{ label: "Comercial" }]}
                    info="As viaturas em stock que vale a pena potenciar com tráfego pago, com a posição de cada uma face ao mercado."
                    filters={isRoot ? (
                        <XSelect
                            small
                            width={240}
                            ariaLabel="Empresa"
                            options={companyOptions}
                            value={selectedCompanyId}
                            onChange={(v) => {
                                setSelectedCompanyId(Number(v) || userCompanyId);
                                setPagination({ pageIndex: 0, pageSize: pagination.pageSize });
                            }}
                            placeholder="Escolher empresa…"
                        />
                    ) : undefined}
                />

                <PageCard
                    title="Que viaturas vamos potenciar com tráfego pago?"
                    info={<>
                        Marque com ★ as que entram na próxima campanha. O orçamento e a ligação ao Meta vêm depois.
                        <br />Mercado: <span className="text-success">verde</span>, no mercado ou abaixo; <span className="text-danger">vermelho</span>, acima do mercado;
                        cinzento, confiança baixa ou sem dados suficientes.
                    </>}
                    status={<>
                        {total} viatura{total === 1 ? "" : "s"}
                        {summary && summary.marked_total > 0 && (
                            <> · {summary.marked_total} marcada{summary.marked_total === 1 ? "" : "s"}</>
                        )}
                    </>}
                    loading={loading && (page?.data?.length ?? 0) > 0}
                    actions={cols.selector}
                    filters={
                        <RestFilterBar activeCount={activeFilterCount} onClear={handleClearFilters}>
                            {filterFields}
                        </RestFilterBar>
                    }
                >
                    <DataTable
                        columns={cols}
                        data={page?.data ?? []}
                        rowKey={(r) => r.id}
                        mode="server"
                        loading={loading}
                        caption="Candidatas a promoção"
                        empty={{ message: activeFilterCount > 0 ? "Sem viaturas com estes filtros." : "Sem viaturas candidatas." }}
                        mobileCard={(r) => renderMobileCard(r)}
                        server={{
                            page: meta.current_page ?? pagination.pageIndex + 1,
                            lastPage: meta.last_page ?? 1,
                            total,
                            perPage: meta.per_page ?? pagination.pageSize,
                            from: meta.from ?? (total ? pagination.pageIndex * pagination.pageSize + 1 : 0),
                            to: meta.to ?? Math.min(total, (pagination.pageIndex + 1) * pagination.pageSize),
                            onPageChange: (p) => setPagination((s) => ({ ...s, pageIndex: p - 1 })),
                            sort: sortBy ? { id: SORT_COLUMN[sortBy], desc: sortDir === "desc" } : null,
                            onSortChange: (next) => {
                                // Sem ordenação volta à de omissão (dias em stock, decrescente); se já era essa,
                                // passa a crescente, para os dias em stock também se poderem ver ao contrário.
                                const isDefault = sortBy === DEFAULT_SORT.by && sortDir === DEFAULT_SORT.dir;
                                setSortBy((next?.key ?? DEFAULT_SORT.by) as ListPromotionCandidatesParams["sort_by"]);
                                setSortDir(next ? (next.desc ? "desc" : "asc") : (isDefault ? "asc" : DEFAULT_SORT.dir));
                                setPagination((s) => ({ ...s, pageIndex: 0 }));
                            },
                        }}
                    />
                </PageCard>
            </Container>
        </div>
    );
};

export default StockPromotionPage;
