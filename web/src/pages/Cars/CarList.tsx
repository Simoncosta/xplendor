import { useEffect, useState, useCallback } from "react";

import { Container, Label } from "reactstrap";

// image
import easyDataIcon from "../../assets/images/icon-easydata.png";

//redux
import { useSelector, useDispatch } from "react-redux";
import { Link, useNavigate } from "react-router-dom";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import XSelect, { XMultiSelect } from "Components/Common/Select";
import CarPriceDisplay from "Components/Common/CarPriceDisplay";
import CarThumbnail from "Components/Common/CarThumbnail";
import { createSelector } from "reselect";
// Slices
import { showCarmine, syncCarmine } from "slices/thunks";
import { getCarsPaginate } from "slices/cars/thunk";
import { getCarBrands } from "slices/car-brands/thunk";
import { getCarModels } from "slices/car-models/thunk";
import { getWorkingCompanyId } from "helpers/workingCompany";

// 2026-06-26 — filtro status passa a múltipla selecção.
type CarStatusFilter = "active" | "sold" | "available_soon" | "reserved" | "draft" | "inactive";
type StatusFilterOption = { value: CarStatusFilter; label: string };
type StockTypeOption = { value: boolean | null; label: string };
type InvestmentFilterOption = { value: boolean | null; label: string };

const statusFilterOptions: StatusFilterOption[] = [
    { value: "active",         label: "Ativos" },
    { value: "draft",          label: "Em Rascunho" },
    { value: "available_soon", label: "Disponível Brevemente" },
    { value: "reserved",       label: "Reservados" },
    { value: "sold",           label: "Vendidos" },
    { value: "inactive",       label: "Inativos" },
];

/**
 * Selecção inicial ao abrir /cars: os 3 estados que o stand normalmente
 * quer ver — activo, rascunho, disponível brevemente. É só o ponto de
 * partida; o utilizador pode desmarcar/marcar à vontade. Desmarcar TUDO
 * = lista vazia com mensagem explicativa.
 */
const DEFAULT_STATUS_FILTERS: CarStatusFilter[] = ["active", "draft", "available_soon"];

/** Compara duas listas de status ignorando ordem (para o activeFilterCount). */
const sameStatusSet = (a: CarStatusFilter[], b: CarStatusFilter[]): boolean => {
    if (a.length !== b.length) return false;
    const setB = new Set(b);
    return a.every((v) => setB.has(v));
};

const stockTypeOptions: StockTypeOption[] = [
    { value: null, label: "Todos" },
    { value: true, label: "Retoma" },
    { value: false, label: "Stock próprio" },
];
// O XSelect só aceita texto ou número: "all" / "resume" / "own" ↔ null / true / false.
const STOCK_KEY = (v: boolean | null) => (v === null ? "all" : v ? "resume" : "own");
const stockTypeSelectOptions = stockTypeOptions.map((o) => ({ value: STOCK_KEY(o.value), label: o.label }));
const FILTER_LABEL = "text-muted fw-semibold fs-11 text-uppercase mb-1";

// Usado pelo filtro "Investimento" (comentado mais abaixo, à espera de reativação).
// eslint-disable-next-line @typescript-eslint/no-unused-vars
const investmentFilterOptions: InvestmentFilterOption[] = [
    { value: null, label: "Todos" },
    { value: true, label: "Com investimento ativo" },
];

const getMetricCount = (items: any) => (Array.isArray(items) ? items.length : Number(items ?? 0));

const getAttentionBadge = (car: any) => {
    const views = getMetricCount(car.views);
    const leads = getMetricCount(car.leads);

    if (views > 500 && leads === 0) {
        return {
            label: "Interesse sem ação",
            className: "bg-warning-subtle text-warning",
            icon: "ri-error-warning-line",
        };
    }

    if (leads > 2) {
        return {
            label: "A converter bem",
            className: "bg-success-subtle text-success",
            icon: "ri-checkbox-circle-line",
        };
    }

    if (views < 50) {
        return {
            label: "Ninguém está a ver",
            className: "bg-secondary-subtle text-secondary",
            icon: "ri-radar-line",
        };
    }

    return {
        label: "Em observação",
        className: "bg-info-subtle text-info",
        icon: "ri-focus-3-line",
    };
};

const formatConversionRate = (views: number, leads: number) => {
    if (!views) return "0%";
    const rate = (leads / views) * 100;
    return `${rate.toFixed(rate >= 1 ? 1 : 2)}%`;
};

const getCarThumbnailUrl = (car: any) => {
    const internalImage = Array.isArray(car.images) && car.images.length > 0
        ? car.images[0]?.image
        : null;

    if (internalImage) {
        return process.env.REACT_APP_PUBLIC_URL + internalImage;
    }

    const externalImage = Array.isArray(car.external_images) && car.external_images.length > 0
        ? car.external_images[0]?.external_url
        : null;

    return externalImage || null;
};

const selectCarmineState = (state: any) => state.Carmine;
const selectCarBrandState = (state: any) => state.CarBrand;
const selectCarModelState = (state: any) => state.CarModel;
const selectCarState = (state: any) => state.Car;

const selectCarmineViewModel = createSelector(
    [selectCarmineState],
    (carmineState: any) => ({
        carmine: carmineState.data.carmine,
        loading: carmineState.loading.show,
    })
);

const selectCarBrandViewModel = createSelector(
    [selectCarBrandState],
    (carBrandState: any) => ({
        brands: carBrandState.data.brands,
        loading: carBrandState.loading.list,
    })
);

const selectCarModelViewModel = createSelector(
    [selectCarModelState],
    (carModelState: any) => ({
        models: carModelState.data.models,
        loading: carModelState.loading.list,
    })
);

const selectCarListViewModel = createSelector(
    [selectCarState],
    (carState: any) => ({
        cars: carState.data.cars,
        meta: carState.data.meta,
        loading: carState.loading.list,
    })
);

const CarList = () => {
    const dispatch: any = useDispatch();

    const { carmine } = useSelector(selectCarmineViewModel);
    const { brands } = useSelector(selectCarBrandViewModel);
    const { models } = useSelector(selectCarModelViewModel);
    const { cars, meta, loading } = useSelector(selectCarListViewModel);

    const navigate = useNavigate();

    // State
    const [companyId, setCompanyId] = useState<any>(null);
    const [carBrandIds, setCarBrandIds] = useState<number[]>([]);
    const [carModelIds, setCarModelIds] = useState<number[]>([]);
    const [statusFilters, setStatusFilters] = useState<CarStatusFilter[]>(DEFAULT_STATUS_FILTERS);
    const [isResumeFilter, setIsResumeFilter] = useState<boolean | null>(null);
    const [hasActiveCampaignFilter, setHasActiveCampaignFilter] = useState<boolean | null>(null);
    const [mincost, setMincost] = useState<number | undefined>(undefined);
    const [maxcost, setMaxcost] = useState<number | undefined>(undefined);
    const [sort, setSort] = useState<{
        field: string | null;
        direction: 'asc' | 'desc' | undefined | null;
    }>({
        field: null,
        direction: null,
    });

    // Paginação controlada no pai (server-side)
    const [pagination, setPagination] = useState({
        pageIndex: 0,
        pageSize: 10,
    });

    // "Filtro activo" = difere do estado inicial. Para o status considera-se
    // activo quando difere do DEFAULT_STATUS_FILTERS (independentemente da
    // ordem em que os chips aparecem no react-select).
    const activeFilterCount = [
        carBrandIds.length > 0,
        carModelIds.length > 0,
        !sameStatusSet(statusFilters, DEFAULT_STATUS_FILTERS),
        isResumeFilter !== null,
        hasActiveCampaignFilter !== null,
        mincost !== undefined,
        maxcost !== undefined,
    ].filter(Boolean).length;

    // Ordenação no servidor (CarRepository::sorts): carro, preço, views, leads, interações e conversão.
    // As chaves são os ids das colunas. Sem ordenação escolhida, a ordem de sempre (pelo id).
    const handleSortChange = useCallback((next: { id: string; key: string; desc: boolean } | null) => {
        setSort(next ? { field: next.key, direction: next.desc ? "desc" : "asc" } : { field: null, direction: null });
        setPagination((p) => ({ ...p, pageIndex: 0 }));
    }, []);

    const handleClearFilters = () => {
        setCarBrandIds([]);
        setCarModelIds([]);
        setMincost(undefined);
        setMaxcost(undefined);
        // "Limpar filtros" repõe o default (não zero — zero = lista vazia
        // com aviso, que seria pior UX que voltar ao ponto inicial).
        setStatusFilters(DEFAULT_STATUS_FILTERS);
        setIsResumeFilter(null);
        setHasActiveCampaignFilter(null);
        setSort({ field: null, direction: null });
    };


    // Fetch sempre que mudar página ou tamanho
    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (authUser) {
            setCompanyId(getWorkingCompanyId());

            // Se o utilizador desmarcou todos os estados, NÃO fazer fetch —
            // o aviso é renderizado em vez da tabela. Evita bater no backend
            // e evita que o BaseRepository devolva "tudo" ao ver `status`
            // vazio (linha 130: `[] → skip filter`).
            if (statusFilters.length > 0) {
                dispatch(
                    getCarsPaginate({
                        page: pagination.pageIndex + 1,
                        perPage: pagination.pageSize,
                        companyId: getWorkingCompanyId(),
                        status: statusFilters,
                        is_resume: isResumeFilter ?? undefined,
                        has_active_campaign: hasActiveCampaignFilter ?? undefined,
                        carBrandIds: carBrandIds,
                        carModelIds: carModelIds,
                        mincost: mincost,
                        maxcost: maxcost,
                        sort: sort.field ?? undefined,
                        dir: sort.direction ?? undefined,
                    })
                );
            }
            dispatch(showCarmine({ companyId: getWorkingCompanyId(), id: 0 }));
            dispatch(getCarBrands());
            if (carBrandIds.length > 0) dispatch(getCarModels(carBrandIds));
        }
    }, [
        dispatch,
        carBrandIds,
        carModelIds,
        statusFilters,
        isResumeFilter,
        hasActiveCampaignFilter,
        mincost,
        maxcost,
        sort,
        pagination.pageIndex,
        pagination.pageSize,
    ]);

    // Actions
    const onClickSyncCarmine = async () => {
        if (!companyId) return;
        dispatch(syncCarmine({ companyId: Number(companyId) }));
        toast("Carros sincronizados com sucesso!", { position: "top-right", hideProgressBar: false, className: 'bg-success text-white' });
    };

    const columnDefs: DTColumn<any>[] = [
        {
            id: "car",
            header: "Carro",
            sortKey: "car", // marca, modelo e versão
            // (o DataTable só ordena colunas com value; em modo servidor a ordem vem da API)
            value: (car) => [car.brand?.name, car.model?.name, car.version].filter(Boolean).join(" "),
            hideable: false,
            mobile: "title",
            cell: (car) => {
                const badge = getAttentionBadge(car);

                return (
                    <div className="d-flex align-items-center">
                        <div className="flex-shrink-0 me-3">
                            <CarThumbnail
                                src={getCarThumbnailUrl(car)}
                                variant="row"
                                width={150}
                            />
                        </div>
                        <div className="flex-grow-1" style={{ minWidth: 0 }}>
                            <div className="d-flex align-items-center flex-wrap gap-2 mb-1">
                                <h5 className="fs-14 mb-0 fw-semibold text-body text-truncate">
                                    {car.brand.name} {car.model.name}
                                </h5>
                                <span className={`badge rounded-pill px-3 py-2 fs-11 ${badge.className}`}>
                                    <i className={`${badge.icon} me-1`} />
                                    {badge.label}
                                </span>
                            </div>
                            <p className="text-muted mb-1 fs-13">
                                <span className="fw-medium">{car.license_plate}</span>
                            </p>
                            <p className="text-muted mb-0 fs-12">
                                Publicado em {new Date(car.car_created_at ?? car.created_at).toLocaleDateString("pt-PT", {
                                    month: "long",
                                    day: "numeric",
                                    year: "numeric",
                                })}
                                {car.is_resume ? " • Retoma" : ""}
                            </p>
                        </div>
                    </div>
                );
            },
        },
        {
            id: "price",
            header: "Preço",
            sortKey: "price",
            value: (car) => car.price_gross,
            cell: (car) => (
                <CarPriceDisplay
                    priceGross={car.price_gross}
                    promoPriceGross={car.promo_price_gross}
                    promoDiscountPct={car.promo_discount_pct}
                    hidePriceOnline={car.hide_price_online}
                    size="sm"
                    badgeLabel="Oportunidade"
                />
            ),
        },
        { id: "views", header: "Views", sortKey: "views", value: (car) => getMetricCount(car.views), cell: (car) => <span className="fw-semibold text-body">{getMetricCount(car.views)}</span> },
        {
            id: "leads",
            header: "Leads",
            sortKey: "leads",
            value: (car) => getMetricCount(car.leads),
            cell: (car) => {
                const leads = getMetricCount(car.leads);
                return <span className={`fw-semibold ${leads > 0 ? "text-success" : "text-body"}`}>{leads}</span>;
            },
        },
        { id: "interactions", header: "Interações", sortKey: "interactions", value: (car) => getMetricCount(car.interactions), cell: (car) => <span className="fw-semibold text-body">{getMetricCount(car.interactions)}</span> },
        {
            id: "conversion",
            header: "Conversão",
            sortKey: "conversion",
            value: (car) => { const v = getMetricCount(car.views); return v ? getMetricCount(car.leads) / v : 0; },
            cell: (car) => (
                <div>
                    <div className="fw-semibold text-body">{formatConversionRate(getMetricCount(car.views), getMetricCount(car.leads))}</div>
                    <div className="text-muted fs-12">leads / views</div>
                </div>
            ),
        },
    ];
    const cols = useDataColumns("comercial.carros", columnDefs);

    const rowActions = (car: any) => (
        <>
            <Link to={`/cars/${car.id}/analytics`} className="btn btn-outline-primary btn-sm" title="Inteligência" aria-label="Inteligência">
                <i className="ri-brain-line" />
            </Link>
            <Link to={`/cars/${car.id}`} className="btn btn-outline-primary btn-sm" title="Editar" aria-label="Editar">
                <i className="ri-pencil-line" />
            </Link>
        </>
    );

    const renderCarMobileCard = useCallback((car: any) => {
        const badge = getAttentionBadge(car);
        const thumbnailUrl = getCarThumbnailUrl(car);
        const views = getMetricCount(car.views);
        const leads = getMetricCount(car.leads);

        return (
            <div
                role="button"
                tabIndex={0}
                onClick={() => navigate(`/cars/${car.id}/analytics`)}
                onKeyDown={(e) => { if (e.key === "Enter") navigate(`/cars/${car.id}/analytics`); }}
                style={{
                    background: "var(--vz-card-bg)",
                    border: "1px solid var(--vz-border-color)",
                    borderRadius: 16,
                    overflow: "hidden",
                    cursor: "pointer",
                }}
            >
                <CarThumbnail src={thumbnailUrl} variant="fullwidth" />

                <div style={{ padding: "12px 14px" }}>
                    <div className="d-flex align-items-center gap-2 mb-2" style={{ minWidth: 0 }}>
                        <h6 className="mb-0 fw-semibold text-body text-truncate" style={{ minWidth: 0, flex: 1 }}>
                            {car.brand.name} {car.model.name}
                        </h6>
                        <span className={`badge rounded-pill px-2 py-1 fs-11 flex-shrink-0 ${badge.className}`}>
                            <i className={`${badge.icon} me-1`} />
                            {badge.label}
                        </span>
                    </div>

                    <div className="mb-3">
                        <CarPriceDisplay
                            priceGross={car.price_gross}
                            promoPriceGross={car.promo_price_gross}
                            promoDiscountPct={car.promo_discount_pct}
                            hidePriceOnline={car.hide_price_online}
                            size="sm"
                            badgeLabel="Oportunidade"
                        />
                    </div>

                    <div className="d-flex gap-2 mb-3">
                        {([
                            { label: "Views", value: String(views) },
                            { label: "Leads", value: String(leads), highlight: leads > 0 },
                            { label: "Conversão", value: formatConversionRate(views, leads) },
                        ] as { label: string; value: string; highlight?: boolean }[]).map((chip) => (
                            <div
                                key={chip.label}
                                style={{
                                    flex: 1,
                                    border: "1px solid var(--vz-border-color)",
                                    borderRadius: 8,
                                    padding: "5px 8px",
                                    background: "var(--vz-tertiary-bg)",
                                    textAlign: "center",
                                }}
                            >
                                <div
                                    className="text-muted fw-semibold text-uppercase"
                                    style={{ fontSize: 10, letterSpacing: "0.06em", marginBottom: 2 }}
                                >
                                    {chip.label}
                                </div>
                                <div className={`fw-semibold fs-13 ${chip.highlight ? "text-success" : "text-body"}`}>
                                    {chip.value}
                                </div>
                            </div>
                        ))}
                    </div>

                    <div style={{ borderTop: "1px solid var(--vz-border-color)", paddingTop: 10 }}>
                        <div className="d-flex align-items-center justify-content-between">
                            <span className="text-muted fs-12">
                                {new Date(car.car_created_at ?? car.created_at).toLocaleDateString("pt-PT", {
                                    day: "numeric",
                                    month: "short",
                                    year: "numeric",
                                })}
                            </span>
                            <div className="d-flex gap-2">
                                <Link
                                    to={`/cars/${car.id}/analytics`}
                                    className="btn btn-outline-primary btn-sm"
                                    title="Inteligência"
                                    aria-label="Inteligência"
                                    onClick={(e) => e.stopPropagation()}
                                    style={{ minWidth: 44, minHeight: 44, display: "inline-flex", alignItems: "center", justifyContent: "center" }}
                                >
                                    <i className="ri-brain-line" />
                                </Link>
                                <Link
                                    to={`/cars/${car.id}`}
                                    className="btn btn-outline-primary btn-sm"
                                    title="Editar"
                                    aria-label="Editar"
                                    onClick={(e) => e.stopPropagation()}
                                    style={{ minWidth: 44, minHeight: 44, display: "inline-flex", alignItems: "center", justifyContent: "center" }}
                                >
                                    <i className="ri-pencil-line" />
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        );
    }, [navigate]);

    const field = (flex = "1 1 180px") => ({ flex, minWidth: 0 });
    const filterFields = (
        <>
            <div style={field()}>
                <Label for="car_brand_id" className={FILTER_LABEL} style={{ letterSpacing: "0.05em" }}>Marca</Label>
                <XMultiSelect
                    id="car_brand_id"
                    small
                    placeholder="Escolha as marcas"
                    options={(brands ?? []).map((b: any) => ({ value: b.id as number, label: b.name }))}
                    value={carBrandIds}
                    onChange={(v) => setCarBrandIds(v)}
                />
            </div>
            <div style={field()}>
                <Label for="car_model_id" className={FILTER_LABEL} style={{ letterSpacing: "0.05em" }}>Modelo</Label>
                <XMultiSelect
                    id="car_model_id"
                    small
                    placeholder={carBrandIds.length === 0 ? "Escolha primeiro uma marca" : "Escolha os modelos"}
                    options={(models ?? []).map((m: any) => ({ value: m.id as number, label: m.name }))}
                    value={carModelIds}
                    onChange={(v) => setCarModelIds(v)}
                    disabled={carBrandIds.length === 0}
                />
            </div>
            <div style={field("1 1 240px")}>
                <Label for="car_status" className={FILTER_LABEL} style={{ letterSpacing: "0.05em" }}>Estado</Label>
                <XMultiSelect
                    id="car_status"
                    small
                    placeholder="Escolha um ou mais estados"
                    options={statusFilterOptions}
                    value={statusFilters}
                    onChange={(v) => setStatusFilters(v)}
                />
            </div>
            <div style={field("1 1 150px")}>
                <Label for="car_stock_type" className={FILTER_LABEL} style={{ letterSpacing: "0.05em" }}>Tipo de stock</Label>
                <XSelect
                    id="car_stock_type"
                    small
                    options={stockTypeSelectOptions}
                    value={STOCK_KEY(isResumeFilter)}
                    onChange={(v) => setIsResumeFilter(v === "all" ? null : v === "resume")}
                    searchable={false}
                />
            </div>
            <div style={field("1 1 220px")}>
                <Label for="minCost" className={FILTER_LABEL} style={{ letterSpacing: "0.05em" }}>Preço</Label>
                <div className="d-flex gap-2 align-items-center">
                    <input className="form-control form-control-sm" type="text" placeholder="Preço de" value={mincost ?? ""}
                        onChange={(e: any) => setMincost(e.target.value === "" ? undefined : e.target.value)} id="minCost" />
                    <span className="fw-semibold text-muted fs-12">até</span>
                    <input className="form-control form-control-sm" type="text" placeholder="Preço até" value={maxcost ?? ""}
                        onChange={(e: any) => setMaxcost(e.target.value === "" ? undefined : e.target.value)} id="maxCost" />
                </div>
            </div>
        </>
    );

    const noStatus = statusFilters.length === 0;
    const total = noStatus ? 0 : (meta?.total ?? 0);

    document.title = "Carros | Xplendor";

    return (
        <div className="page-content">
            <ToastContainer closeButton={false} limit={1} />
            <Container fluid>
                <PageHeader
                    title="Carros"
                    breadcrumbs={[{ label: "Comercial" }]}
                    info="As viaturas em stock, com o desempenho comercial de cada uma."
                />

                <PageCard
                    title="Viaturas"
                    info={<>
                        Cada viatura tem um sinal comercial: <strong>Interesse sem ação</strong> (mais de 500 views e nenhuma lead),{" "}
                        <strong>A converter bem</strong> (mais de 2 leads), <strong>Ninguém está a ver</strong> (menos de 50 views){" "}
                        e <strong>Em observação</strong> (o resto).
                    </>}
                    status={<>{total} viatura{total === 1 ? "" : "s"}</>}
                    loading={loading && (cars?.length ?? 0) > 0}
                    actions={<>
                        {cols.selector}
                        {carmine && carmine.id && (
                            <button type="button" onClick={onClickSyncCarmine} className="btn btn-outline-primary btn-sm">
                                <img src={easyDataIcon} alt="EasyData" width={10} className="me-1" />
                                Sincronizar
                            </button>
                        )}
                        <Link to="/cars/create" className="btn btn-primary btn-sm">
                            <i className="ri-add-line align-bottom me-1"></i>
                            Nova viatura
                        </Link>
                    </>}
                    filters={
                        <RestFilterBar activeCount={activeFilterCount} onClear={handleClearFilters}>
                            {filterFields}
                        </RestFilterBar>
                    }
                >
                    <DataTable
                        columns={cols}
                        data={noStatus ? [] : (cars ?? [])}
                        rowKey={(car: any) => car.id}
                        mode="server"
                        loading={!noStatus && loading}
                        caption="Viaturas"
                        empty={{
                            message: noStatus
                                ? <>Nenhum estado selecionado. Escolha pelo menos um estado no filtro <strong>Estado</strong> para ver viaturas.</>
                                : "Sem viaturas com estes filtros.",
                        }}
                        rowActions={rowActions}
                        mobileCard={(car) => renderCarMobileCard(car)}
                        server={noStatus ? undefined : {
                            page: meta?.current_page ?? pagination.pageIndex + 1,
                            lastPage: meta?.last_page ?? 1,
                            total: meta?.total ?? 0,
                            perPage: meta?.per_page ?? pagination.pageSize,
                            from: meta?.from ?? 0,
                            to: meta?.to ?? 0,
                            onPageChange: (p) => setPagination((s) => ({ ...s, pageIndex: p - 1 })),
                            sort: sort.field ? { id: sort.field, desc: sort.direction === "desc" } : null,
                            onSortChange: handleSortChange,
                        }}
                    />
                </PageCard>
            </Container>
        </div>
    );
};

export default CarList;
