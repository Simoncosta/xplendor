import { useCallback, useEffect, useMemo, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { Link } from "react-router-dom";
import { ToastContainer } from "react-toastify";
import Select from "react-select";
import {
    Card,
    CardBody,
    CardHeader,
    Col,
    Container,
    Input,
    Label,
    Offcanvas,
    OffcanvasBody,
    OffcanvasHeader,
    Row,
} from "reactstrap";
import { createSelector } from "reselect";
import XTanStackTable from "Components/Common/XTanStackTable";
import { useIsMobile } from "../../hooks/useIsMobile";
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
import MarketLegendHeader from "./components/MarketLegendHeader";
import PromotionMobileCard from "./components/PromotionMobileCard";
import CarThumbnail from "Components/Common/CarThumbnail";

// ── Design tokens espelhados da CarList ──────────────────────────────────────
// Cards: sombra suave + gradiente vertical branco→quase-branco.
// CardHeader: gradiente azul-Velzon (#405189) muito subtil.
// Reaproveitamos como objects para garantir consistência byte-a-byte.
const CARD_SHADOW: React.CSSProperties = {
    boxShadow: "0 16px 40px rgba(15, 23, 42, 0.08)",
    background: "linear-gradient(180deg, #ffffff 0%, #fcfcfd 100%)",
};
const CARD_HEADER_GRADIENT: React.CSSProperties = {
    background: "linear-gradient(180deg, rgba(64,81,137,0.05) 0%, rgba(64,81,137,0.015) 100%)",
};
const EYEBROW_STYLE: React.CSSProperties = { letterSpacing: "0.08em" };
const LABEL_STYLE: React.CSSProperties = { letterSpacing: "0.05em" };

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

const SORT_OPTIONS = [
    { value: "days_in_stock", label: "Dias em stock" },
    { value: "price",         label: "Preço" },
    { value: "views",         label: "Visualizações" },
    { value: "leads",         label: "Leads" },
    { value: "ips",           label: "IPS" },
];

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
    const isMobile = useIsMobile(768);
    const isFiltersMobile = useIsMobile(992);

    // ── Auth / company resolution ─────────────────────────────────────────
    const [userRole, setUserRole] = useState<string | null>(null);
    const [userCompanyId, setUserCompanyId] = useState<number | null>(null);
    const [selectedCompanyId, setSelectedCompanyId] = useState<number | null>(null);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;
        const obj = JSON.parse(authUser);
        setUserRole(obj.role);
        setUserCompanyId(obj.company_id);
        setSelectedCompanyId(obj.company_id);
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
    const [sortBy, setSortBy] = useState<ListPromotionCandidatesParams["sort_by"]>("days_in_stock");
    const [sortDir, setSortDir] = useState<"asc" | "desc">("desc");
    const [filtersOpen, setFiltersOpen] = useState(false);

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

    // ── Sidebar de filtros (mesmas labels uppercase letterspacing da CarList) ──
    const filterPanelContent = (
        <>
            <div className="filter-choices-input mb-4">
                <Label className="text-muted fw-semibold fs-12 text-uppercase" style={LABEL_STYLE}>
                    Tipo de viatura
                </Label>
                <Select
                    isClearable
                    placeholder="Todos os tipos"
                    options={VEHICLE_TYPE_OPTIONS}
                    value={VEHICLE_TYPE_OPTIONS.find((o) => o.value === vehicleType) || null}
                    onChange={(opt: any) => setVehicleType(opt?.value ?? null)}
                />
            </div>

            <div className="filter-choices-input mb-4">
                <Label className="text-muted fw-semibold fs-12 text-uppercase" style={LABEL_STYLE}>
                    Estado
                </Label>
                <Select
                    isMulti
                    placeholder="Todos os estados"
                    options={STATUS_OPTIONS}
                    value={STATUS_OPTIONS.filter((o) => statuses.includes(o.value))}
                    onChange={(opts: any) => setStatuses((opts || []).map((o: any) => o.value))}
                />
            </div>

            <div className="filter-choices-input mb-4">
                <Label className="text-muted fw-semibold fs-12 text-uppercase" style={LABEL_STYLE}>
                    Posição vs mercado
                </Label>
                <Select
                    isMulti
                    placeholder="Qualquer posição"
                    options={PRICE_SIGNAL_OPTIONS}
                    value={PRICE_SIGNAL_OPTIONS.filter((o) => priceSignals.includes(o.value))}
                    onChange={(opts: any) => setPriceSignals((opts || []).map((o: any) => o.value))}
                />
            </div>

            <div className="filter-choices-input mb-4">
                <Label className="text-muted fw-semibold fs-12 text-uppercase" style={LABEL_STYLE}>
                    Dias em stock
                </Label>
                <div className="formCost d-flex gap-2 align-items-center">
                    <Input
                        type="number"
                        min={0}
                        placeholder="De"
                        value={minDays}
                        onChange={(e) => setMinDays(e.target.value)}
                        className="form-control form-control-sm"
                    />
                    <span className="fw-semibold text-muted">até</span>
                    <Input
                        type="number"
                        min={0}
                        placeholder="Até"
                        value={maxDays}
                        onChange={(e) => setMaxDays(e.target.value)}
                        className="form-control form-control-sm"
                    />
                </div>
            </div>

            <div className="filter-choices-input mb-4">
                <Label className="text-muted fw-semibold fs-12 text-uppercase" style={LABEL_STYLE}>
                    Preço (€)
                </Label>
                <div className="formCost d-flex gap-2 align-items-center">
                    <Input
                        type="number"
                        min={0}
                        placeholder="Preço de"
                        value={minPrice}
                        onChange={(e) => setMinPrice(e.target.value)}
                        className="form-control form-control-sm"
                    />
                    <span className="fw-semibold text-muted">até</span>
                    <Input
                        type="number"
                        min={0}
                        placeholder="Preço até"
                        value={maxPrice}
                        onChange={(e) => setMaxPrice(e.target.value)}
                        className="form-control form-control-sm"
                    />
                </div>
            </div>

            <div className="form-check mb-4">
                <Input
                    type="checkbox"
                    id="only-marked"
                    className="form-check-input"
                    checked={onlyMarked}
                    onChange={(e) => setOnlyMarked(e.target.checked)}
                />
                <Label for="only-marked" className="form-check-label">
                    Só com prioridade marcada
                </Label>
            </div>

            <div className="filter-choices-input">
                <Label className="text-muted fw-semibold fs-12 text-uppercase" style={LABEL_STYLE}>
                    Ordenar por
                </Label>
                <div className="d-flex gap-2 align-items-center">
                    <div style={{ flex: 1 }}>
                        <Select
                            options={SORT_OPTIONS}
                            value={SORT_OPTIONS.find((o) => o.value === sortBy)}
                            onChange={(opt: any) => setSortBy(opt?.value)}
                        />
                    </div>
                    <button
                        type="button"
                        className="btn btn-soft-secondary btn-sm"
                        onClick={() => setSortDir(sortDir === "desc" ? "asc" : "desc")}
                        title={sortDir === "desc" ? "Descendente — clica para ascendente" : "Ascendente — clica para descendente"}
                        style={{ minWidth: 40 }}
                    >
                        <i className={sortDir === "desc" ? "ri-sort-desc" : "ri-sort-asc"} />
                    </button>
                </div>
            </div>
        </>
    );

    // ── Tabela: coluna "Viatura" espelha o layout da CarList ──────────────
    const columns = useMemo(() => [
        {
            header: "Viatura",
            accessorKey: "brand",
            enableSorting: false,
            enableColumnFilter: false,
            cell: (c: any) => {
                const r = c.row.original as PromotionCandidate;
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
            header: "Preço",
            accessorKey: "price",
            enableSorting: false,
            enableColumnFilter: false,
            cell: (c: any) => {
                const r = c.row.original as PromotionCandidate;
                return (
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
                );
            },
        },
        {
            header: () => <MarketLegendHeader />,
            accessorKey: "market",
            enableSorting: false,
            enableColumnFilter: false,
            cell: (c: any) => {
                const r = c.row.original as PromotionCandidate;
                return <MarketChip market={r.market} rowId={r.id} />;
            },
        },
        {
            header: "IPS",
            accessorKey: "ips",
            enableSorting: false,
            enableColumnFilter: false,
            cell: (c: any) => {
                const r = c.row.original as PromotionCandidate;
                const badge = formatIpsBadge(r.ips?.score, r.ips?.classification);
                return <span className={`badge ${badge.className} fw-normal`}>{badge.shortLabel}</span>;
            },
        },
        {
            header: "Em stock",
            accessorKey: "days_in_stock",
            enableSorting: false,
            enableColumnFilter: false,
            cell: (c: any) => {
                const r = c.row.original as PromotionCandidate;
                return (
                    <div>
                        <div className="fw-semibold text-body">
                            {r.days_in_stock} <span className="fw-normal text-muted fs-12">{r.days_in_stock === 1 ? "dia" : "dias"}</span>
                        </div>
                    </div>
                );
            },
        },
        {
            header: "Tráfego",
            accessorKey: "engagement",
            enableSorting: false,
            enableColumnFilter: false,
            cell: (c: any) => {
                const r = c.row.original as PromotionCandidate;
                return (
                    <div>
                        <div className="fw-semibold text-body">
                            {r.engagement.views_count} <span className="fw-normal text-muted fs-12">views</span>
                        </div>
                        <div className={`fw-semibold ${r.engagement.leads_count > 0 ? "text-success" : "text-body"}`}>
                            {r.engagement.leads_count} <span className="fw-normal text-muted fs-12">leads</span>
                        </div>
                    </div>
                );
            },
        },
        {
            header: "Promover",
            accessorKey: "promotion",
            enableSorting: false,
            enableColumnFilter: false,
            cell: (c: any) => {
                const r = c.row.original as PromotionCandidate;
                return (
                    <div className="d-flex flex-column align-items-start gap-1">
                        {selectedCompanyId !== null && (
                            <StarToggle
                                companyId={selectedCompanyId}
                                carId={r.id}
                                initial={r.promotion}
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
                );
            },
        },
    ], [selectedCompanyId, handlePriorityChanged]);

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
    const lastPage = page?.meta?.last_page ?? 1;

    document.title = "Candidatas a promoção | Xplendor";

    return (
        <div className="page-content">
            <ToastContainer closeButton={false} limit={1} />
            <Container fluid>
                {/* HEADER DA PÁGINA — espelha CarList */}
                <Row className="mb-3">
                    <Col>
                        <div className="d-flex align-items-start justify-content-between flex-wrap gap-3">
                            <div>
                                <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={EYEBROW_STYLE}>
                                    Gestão de Stock
                                </p>
                                <h3 className="mb-1 fw-semibold">Candidatas a promoção</h3>
                            </div>
                            <div className="d-flex gap-2 flex-wrap align-items-center">
                                {isRoot && (
                                    <div style={{ minWidth: 240 }}>
                                        <Select
                                            options={companyOptions}
                                            value={companyOptions.find((o: any) => o.value === selectedCompanyId) || null}
                                            onChange={(opt: any) => {
                                                setSelectedCompanyId(opt?.value ?? userCompanyId);
                                                setPagination({ pageIndex: 0, pageSize: pagination.pageSize });
                                            }}
                                            placeholder="Escolher empresa..."
                                        />
                                    </div>
                                )}
                                {isFiltersMobile && (
                                    <button
                                        type="button"
                                        className="btn btn-soft-secondary btn-sm"
                                        onClick={() => setFiltersOpen(true)}
                                    >
                                        <i className="ri-filter-line me-1" />
                                        {activeFilterCount > 0 ? `Filtros (${activeFilterCount})` : "Filtros"}
                                    </button>
                                )}
                            </div>
                        </div>
                    </Col>
                </Row>

                <Row>
                    {/* SIDEBAR DE FILTROS — card com gradiente espelhado */}
                    {!isFiltersMobile && (
                        <Col xl={3} lg={4}>
                            <Card className="border-0" style={CARD_SHADOW}>
                                <CardHeader
                                    className="border-bottom-0"
                                    style={{ ...CARD_HEADER_GRADIENT, padding: "1.25rem 1.25rem 0 1.25rem" }}
                                >
                                    <div className="d-flex mb-3 align-items-start justify-content-between gap-2">
                                        <div className="flex-grow-1">
                                            <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={EYEBROW_STYLE}>
                                                Filtros
                                            </p>
                                            <h5 className="fs-16 mb-1 fw-semibold">Refinar candidatas</h5>
                                            <p className="text-muted fs-13 mb-0">
                                                Afina por preço, idade, posição no mercado e prioridades já marcadas.
                                            </p>
                                        </div>
                                        <div className="flex-shrink-0">
                                            <button
                                                type="button"
                                                onClick={handleClearFilters}
                                                className="btn btn-link text-decoration-none p-0 fs-13"
                                                disabled={activeFilterCount === 0}
                                            >
                                                Limpar todos
                                            </button>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardBody className="pt-2">
                                    {filterPanelContent}
                                </CardBody>
                            </Card>
                        </Col>
                    )}

                    {/* LISTA — card espelhado com sub-section da legenda */}
                    <Col xl={isFiltersMobile ? 12 : 9} lg={isFiltersMobile ? 12 : 8}>
                        <Card className="border-0 overflow-hidden" style={CARD_SHADOW}>
                            <CardHeader
                                className="border-bottom-0"
                                style={{ ...CARD_HEADER_GRADIENT, padding: "1rem 1rem 0 1rem" }}
                            >
                                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3 px-2">
                                    <div>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={EYEBROW_STYLE}>
                                            Decisão de Promoção
                                        </p>
                                        <h5 className="mb-1 fw-semibold">Que viaturas vamos potenciar com tráfego pago?</h5>
                                        <p className="text-muted fs-13 mb-0">
                                            Marca com ★ as que entram na próxima campanha. O orçamento e a ligação ao Meta vêm depois.
                                        </p>
                                    </div>
                                    <span className="badge bg-light text-muted fs-12 px-3 py-2">
                                        {total} viatura{total === 1 ? "" : "s"}
                                        {summary && summary.marked_total > 0 && (
                                            <> · {summary.marked_total} marcada{summary.marked_total === 1 ? "" : "s"}</>
                                        )}
                                    </span>
                                </div>
                            </CardHeader>
                            <CardBody className="pt-3">
                                {/* Sub-section "Legenda de Mercado" — espelha o bloco "Prioridade Comercial" da CarList */}
                                <div className="mb-3 px-1">
                                    <div className="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                        <div>
                                            <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={EYEBROW_STYLE}>
                                                Posição vs Mercado
                                            </p>
                                            <h6 className="mb-0 fw-semibold">Como ler o chip de mercado</h6>
                                        </div>
                                        <div className="d-flex gap-2 flex-wrap">
                                            <span className="badge bg-success-subtle text-success px-3 py-2 fs-12">
                                                No mercado ou abaixo
                                            </span>
                                            <span className="badge bg-danger-subtle text-danger px-3 py-2 fs-12">
                                                Acima do mercado
                                            </span>
                                            <span className="badge bg-light text-muted px-3 py-2 fs-12">
                                                Confiança baixa
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div className="pt-1">
                                    <XTanStackTable
                                        columns={columns}
                                        data={page?.data ?? []}
                                        loading={loading}
                                        pagination={pagination}
                                        onPaginationChange={setPagination}
                                        pageCount={lastPage}
                                        total={total}
                                        customPageSize={pagination.pageSize}
                                        mobileMode={isMobile}
                                        renderMobileCard={renderMobileCard}
                                        isBordered={true}
                                        theadClass="text-muted table-light"
                                    />
                                </div>
                            </CardBody>
                        </Card>
                    </Col>
                </Row>

                {/* Off-canvas em mobile/tablet — espelha CarList */}
                {isFiltersMobile && (
                    <Offcanvas
                        isOpen={filtersOpen}
                        toggle={() => setFiltersOpen(false)}
                        direction="start"
                        scrollable
                    >
                        <OffcanvasHeader toggle={() => setFiltersOpen(false)}>
                            <div>
                                <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={EYEBROW_STYLE}>
                                    Filtros
                                </p>
                                <span className="fw-semibold fs-16">Refinar candidatas</span>
                            </div>
                        </OffcanvasHeader>
                        <OffcanvasBody>
                            <div className="d-flex justify-content-end mb-4">
                                <button
                                    type="button"
                                    onClick={handleClearFilters}
                                    className="btn btn-link text-decoration-none p-0 fs-13"
                                    disabled={activeFilterCount === 0}
                                >
                                    Limpar todos
                                </button>
                            </div>
                            {filterPanelContent}
                        </OffcanvasBody>
                    </Offcanvas>
                )}
            </Container>
        </div>
    );
};

export default StockPromotionPage;
