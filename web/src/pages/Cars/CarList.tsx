import { useEffect, useState, useMemo, useCallback } from "react";

import {
    Container,
    Row,
    Card,
    CardHeader,
    CardBody,
    Col,
    Label,
} from "reactstrap";

// Select Form
import Select from "react-select";

// image
import easyDataIcon from "../../assets/images/icon-easydata.png";

//redux
import { useSelector, useDispatch } from "react-redux";
import { Link } from "react-router-dom";
import { toast, ToastContainer } from "react-toastify";
import XTanStackTable from "Components/Common/XTanStackTable";
import CarPriceDisplay from "Components/Common/CarPriceDisplay";
import { createSelector } from "reselect";
// Slices
import { showCarmine, syncCarmine } from "slices/thunks";
import { getCarsPaginate } from "slices/cars/thunk";
import { getCarBrands } from "slices/car-brands/thunk";
import { getCarModels } from "slices/car-models/thunk";

type CarStatusFilter = "active" | "sold" | "available_soon" | "draft";
type StatusFilterOption = { value: CarStatusFilter | null; label: string };
type StockTypeOption = { value: boolean | null; label: string };
type InvestmentFilterOption = { value: boolean | null; label: string };

const statusFilterOptions: StatusFilterOption[] = [
    { value: null, label: "Todos" },
    { value: "active", label: "Ativos" },
    { value: "sold", label: "Vendidos" },
    { value: "available_soon", label: "Disponível Brevemente" },
    { value: "draft", label: "Em Rascunho" },
];

const stockTypeOptions: StockTypeOption[] = [
    { value: null, label: "Todos" },
    { value: true, label: "Retoma" },
    { value: false, label: "Stock proprio" },
];

const investmentFilterOptions: InvestmentFilterOption[] = [
    { value: null, label: "Todos" },
    { value: true, label: "Com investimento activo" },
];

const getMetricCount = (items: any) => (Array.isArray(items) ? items.length : Number(items ?? 0));

const getAttentionBadge = (car: any) => {
    const views = getMetricCount(car.views);
    const leads = getMetricCount(car.leads);

    if (views > 500 && leads === 0) {
        return {
            label: "Interesse sem acção",
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
        label: "Em observacao",
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

    // State
    const [isMobile, setIsMobile] = useState(window.innerWidth < 680);
    const [companyId, setCompanyId] = useState<any>(null);
    const [carBrandIds, setCarBrandIds] = useState<number[]>([]);
    const [carModelIds, setCarModelIds] = useState<number[]>([]);
    const [statusFilter, setStatusFilter] = useState<CarStatusFilter | null>("active");
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

    // Actions
    const handleSortChange = useCallback((sorting: any) => {
        setSort((prev) => {
            const next = {
                field: sorting?.field ?? null,
                direction: sorting?.direction ?? null,
            };

            if (
                prev.field === next.field &&
                prev.direction === next.direction
            ) {
                return prev;
            }

            return next;
        });
    }, []);

    // Paginação controlada no pai (server-side)
    const [pagination, setPagination] = useState({
        pageIndex: 0,
        pageSize: 10,
    });

    // Fetch sempre que mudar página ou tamanho
    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (authUser) {
            const obj = JSON.parse(authUser);
            setCompanyId(obj.company_id);

            dispatch(
                getCarsPaginate({
                    page: pagination.pageIndex + 1,
                    perPage: pagination.pageSize,
                    companyId: obj.company_id,
                    status: statusFilter ?? undefined,
                    is_resume: isResumeFilter ?? undefined,
                    has_active_campaign: hasActiveCampaignFilter ?? undefined,
                    carBrandIds: carBrandIds,
                    carModelIds: carModelIds,
                    mincost: mincost,
                    maxcost: maxcost,
                    sort_by: sort.field ?? undefined,
                    sort_direction: sort.direction ?? undefined,
                })
            );
            dispatch(showCarmine({ companyId: obj.company_id, id: 0 }));
            dispatch(getCarBrands());
            if (carBrandIds.length > 0) dispatch(getCarModels(carBrandIds));
        }
    }, [
        dispatch,
        carBrandIds,
        carModelIds,
        statusFilter,
        isResumeFilter,
        hasActiveCampaignFilter,
        mincost,
        maxcost,
        sort,
        pagination.pageIndex,
        pagination.pageSize,
    ]);

    useEffect(() => {
        const handleResize = () => {
            setIsMobile(window.innerWidth < 680);
        };

        window.addEventListener("resize", handleResize);
        return () => window.removeEventListener("resize", handleResize);
    }, []);

    // Actions
    const onClickSyncCarmine = async () => {
        if (!companyId) return;
        dispatch(syncCarmine({ companyId: Number(companyId) }));
        toast("Carros sincronizados com sucesso!", { position: "top-right", hideProgressBar: false, className: 'bg-success text-white' });
    };

    const columns = useMemo(() => [
        {
            header: "Carro",
            accessorKey: "brand",
            enableColumnFilter: false,
            cell: (cell: any) => {
                const car = cell.row.original;
                const badge = getAttentionBadge(car);

                return (
                    <div className="d-flex align-items-center">
                        {getCarThumbnailUrl(car) && (
                            <div className="flex-shrink-0 me-3">
                                <img
                                    src={getCarThumbnailUrl(car) as string}
                                    alt=""
                                    className="img-thumbnail border-0"
                                    width={150}
                                    style={{
                                        borderRadius: "1rem",
                                        objectFit: "cover",
                                        boxShadow: "0 8px 24px rgba(15, 23, 42, 0.08)",
                                    }}
                                />
                            </div>
                        )}
                        <div className="flex-grow-1">
                            <div className="d-flex align-items-center flex-wrap gap-2 mb-1">
                                <h5 className="fs-14 mb-0 fw-semibold text-body">
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
            header: "Preço",
            accessorKey: "price_gross",
            enableColumnFilter: false,
            cell: (cell: any) => {
                const car = cell.row.original;

                return (
                    <CarPriceDisplay
                        priceGross={car.price_gross}
                        promoPriceGross={car.promo_price_gross}
                        promoDiscountPct={car.promo_discount_pct}
                        size="sm"
                        badgeLabel="Oportunidade"
                    />
                );
            }
        },
        {
            header: "Views",
            accessorKey: "views",
            enableColumnFilter: false,
            cell: (cell: any) => (
                <div>
                    <div className="fw-semibold text-body">{getMetricCount(cell.row.original.views)}</div>
                </div>
            )
        },
        {
            header: "Leads",
            accessorKey: "leads",
            enableColumnFilter: false,
            cell: (cell: any) => {
                const leads = getMetricCount(cell.row.original.leads);
                return (
                    <div>
                        <div className={`fw-semibold ${leads > 0 ? "text-success" : "text-body"}`}>{leads}</div>
                    </div>
                );
            },
        },
        {
            header: "Interações",
            accessorKey: "interactions",
            enableColumnFilter: false,
            cell: (cell: any) => (
                <div>
                    <div className="fw-semibold text-body">{getMetricCount(cell.row.original.interactions)}</div>
                </div>
            )
        },
        {
            header: "Conversão",
            accessorKey: "conversion_rate",
            enableColumnFilter: false,
            cell: (cell: any) => {
                const views = getMetricCount(cell.row.original.views);
                const leads = getMetricCount(cell.row.original.leads);

                return (
                    <div>
                        <div className="fw-semibold text-body">{formatConversionRate(views, leads)}</div>
                        <div className="text-muted fs-12">leads / views</div>
                    </div>
                );
            },
        },
        {
            header: "Ação",
            cell: (cell: any) => {
                const id = cell.row.original.id;

                return (
                    <div className="d-flex gap-2">
                        <Link
                            to={`/cars/${id}/analytics`}
                            className="btn btn-soft-info btn-sm"
                            title="Inteligência"
                        >
                            <i className="ri-brain-line" />
                        </Link>
                        <Link
                            to={`/cars/${id}`}
                            className="btn btn-soft-primary btn-sm"
                            title="Editar"
                        >
                            <i className="ri-pencil-line" />
                        </Link>
                    </div>
                );
            },
        }
    ],
        []
    );

    document.title = "Carros | Xplendor";

    return (
        <div className="page-content">
            <ToastContainer closeButton={false} limit={1} />
            <Container fluid>
                <Row className="mb-3">
                    <Col>
                        <div className="d-flex align-items-start justify-content-between flex-wrap gap-3">
                            <div>
                                <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                    Painel Comercial
                                </p>
                                <h3 className="mb-1 fw-semibold">Carros</h3>
                            </div>
                            <div className="d-flex gap-2 flex-wrap">
                                {carmine && carmine.id && (
                                    <button onClick={onClickSyncCarmine} className="btn btn-soft-danger">
                                        <img src={easyDataIcon} alt="EasyData" width={10} className="me-1" />
                                        Sincronizar
                                    </button>
                                )}
                                <Link to="/cars/create" className="btn btn-primary">
                                    <i className="ri-add-line align-bottom me-1"></i>
                                    Nova viatura
                                </Link>
                            </div>
                        </div>
                    </Col>
                </Row>

                <Row>
                    <Col xl={3} lg={4}>
                        <Card
                            className="border-0"
                            style={{
                                boxShadow: "0 16px 40px rgba(15, 23, 42, 0.08)",
                                background: "linear-gradient(180deg, #ffffff 0%, #fcfcfd 100%)",
                            }}
                        >
                            <CardHeader
                                className="border-bottom-0"
                                style={{
                                    padding: "1.25rem 1.25rem 0 1.25rem",
                                    background: "linear-gradient(180deg, rgba(64,81,137,0.05) 0%, rgba(64,81,137,0.015) 100%)",
                                }}
                            >
                                <div className="d-flex mb-3 align-items-start justify-content-between gap-2">
                                    <div className="flex-grow-1">
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                            Filtros
                                        </p>
                                        <h5 className="fs-16 mb-1 fw-semibold">Refinar listagem</h5>
                                        <p className="text-muted fs-13 mb-0">Encontra rapidamente as viaturas que pedem ação.</p>
                                    </div>
                                    <div className="flex-shrink-0">
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setCarBrandIds([]);
                                                setCarModelIds([]);
                                                setMincost(undefined);
                                                setMaxcost(undefined);
                                                setStatusFilter(null);
                                                setIsResumeFilter(null);
                                                setHasActiveCampaignFilter(null);
                                                setSort({
                                                    field: null,
                                                    direction: null,
                                                });
                                            }}
                                            className="btn btn-link text-decoration-none p-0 fs-13"
                                        >
                                            Limpar todos
                                        </button>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardBody className="pt-2">
                                <div className="filter-choices-input mb-3">
                                    <Label for="car_brand_id" className="text-muted fw-semibold fs-12 text-uppercase" style={{ letterSpacing: "0.05em" }}>Marca</Label>
                                    <Select
                                        placeholder="Selecione as marcas"
                                        options={brands}
                                        getOptionLabel={(option: any) => option.name}
                                        getOptionValue={(option: any) => String(option.id)}
                                        isMulti
                                        value={brands.filter((brand: any) => carBrandIds?.includes(brand.id))}
                                        onChange={(selected: any) => {
                                            setCarBrandIds(selected ? selected.map((item: any) => item.id) : []);
                                        }}
                                    />
                                </div>
                                <div className="filter-choices-input mb-4">
                                    <Label for="car_model_id" className="text-muted fw-semibold fs-12 text-uppercase" style={{ letterSpacing: "0.05em" }}>Modelo</Label>
                                    <Select
                                        placeholder="Selecione os modelos"
                                        options={models}
                                        getOptionLabel={(option: any) => option.name}
                                        getOptionValue={(option: any) => String(option.id)}
                                        isMulti
                                        value={models.filter((model: any) => carModelIds?.includes(model.id))}
                                        onChange={(selected: any) => {
                                            setCarModelIds(selected ? selected.map((item: any) => item.id) : []);
                                        }}
                                        isDisabled={carBrandIds.length === 0}
                                    />
                                </div>
                                <div className="filter-choices-input mb-4">
                                    <Label for="car_status" className="text-muted fw-semibold fs-12 text-uppercase" style={{ letterSpacing: "0.05em" }}>Status</Label>
                                    <Select
                                        inputId="car_status"
                                        placeholder="Todos os estados"
                                        options={statusFilterOptions}
                                        isClearable={false}
                                        value={statusFilterOptions.find((option) => option.value === statusFilter) ?? statusFilterOptions[0]}
                                        onChange={(selected: StatusFilterOption | null) => {
                                            setStatusFilter(selected?.value ?? null);
                                        }}
                                    />
                                </div>
                                <div className="filter-choices-input mb-4">
                                    <Label for="car_stock_type" className="text-muted fw-semibold fs-12 text-uppercase" style={{ letterSpacing: "0.05em" }}>Tipo de stock</Label>
                                    <Select
                                        inputId="car_stock_type"
                                        placeholder="Todo o stock"
                                        options={stockTypeOptions}
                                        isClearable={false}
                                        value={stockTypeOptions.find((option) => option.value === isResumeFilter) ?? stockTypeOptions[0]}
                                        onChange={(selected: StockTypeOption | null) => {
                                            setIsResumeFilter(selected?.value ?? null);
                                        }}
                                    />
                                </div>
                                <div className="filter-choices-input mb-4">
                                    <Label for="car_investment_status" className="text-muted fw-semibold fs-12 text-uppercase" style={{ letterSpacing: "0.05em" }}>Investimento</Label>
                                    <Select
                                        inputId="car_investment_status"
                                        placeholder="Todo o stock"
                                        options={investmentFilterOptions}
                                        isClearable={false}
                                        value={investmentFilterOptions.find((option) => option.value === hasActiveCampaignFilter) ?? investmentFilterOptions[0]}
                                        onChange={(selected: InvestmentFilterOption | null) => {
                                            setHasActiveCampaignFilter(selected?.value ?? null);
                                        }}
                                    />
                                </div>
                                <div className="filter-choices-input">
                                    <Label for="car_model_id" className="text-muted fw-semibold fs-12 text-uppercase" style={{ letterSpacing: "0.05em" }}>Preço</Label>
                                    <div className="formCost d-flex gap-2 align-items-center">
                                        <input
                                            className="form-control form-control-sm"
                                            type="text"
                                            placeholder="Preço de"
                                            value={mincost}
                                            onChange={(e: any) => setMincost(e.target.value)}
                                            id="minCost"
                                        />
                                        <span className="fw-semibold text-muted">até</span>
                                        <input
                                            className="form-control form-control-sm"
                                            type="text"
                                            placeholder="Preço até"
                                            value={maxcost}
                                            onChange={(e: any) => setMaxcost(e.target.value)}
                                            id="maxCost"
                                        />
                                    </div>
                                </div>
                            </CardBody>
                        </Card>
                    </Col>

                    <Col xl={9} lg={8}>
                        <Card
                            className="border-0 overflow-hidden"
                            style={{
                                boxShadow: "0 16px 40px rgba(15, 23, 42, 0.08)",
                                background: "linear-gradient(180deg, #ffffff 0%, #fcfcfd 100%)",
                            }}
                        >
                            <CardHeader
                                className="border-bottom-0"
                                style={{
                                    padding: "1rem 1rem 0 1rem",
                                    background: "linear-gradient(180deg, rgba(64,81,137,0.05) 0%, rgba(64,81,137,0.015) 100%)",
                                }}
                            >
                                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3 px-2">
                                    <div>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                            Gestão de Stock
                                        </p>
                                        <h5 className="mb-1 fw-semibold">Viaturas acompanhadas por filtros e desempenho</h5>
                                        <p className="text-muted fs-13 mb-0">
                                            Encontra rapidamente as viaturas certas e prioriza as que pedem mais atenção comercial.
                                        </p>
                                    </div>
                                    <span className="badge bg-light text-muted fs-12 px-3 py-2">
                                        {meta?.total ?? 0} viatura{meta?.total === 1 ? "" : "s"}
                                    </span>
                                </div>
                            </CardHeader>
                            <CardBody className="pt-3">
                                <div className="mb-3 px-1">
                                    <div className="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                        <div>
                                            <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                                Prioridade Comercial
                                            </p>
                                            <h6 className="mb-0 fw-semibold">Que carros pedem decisão agora?</h6>
                                        </div>
                                        <div className="d-flex gap-2 flex-wrap">
                                            <span className="badge bg-danger-subtle text-danger px-3 py-2 fs-12">Precisa de atenção agora</span>
                                            <span className="badge bg-warning-subtle text-warning px-3 py-2 fs-12">Acompanhar esta semana</span>
                                            <span className="badge bg-secondary-subtle text-secondary px-3 py-2 fs-12">Monitorizar</span>
                                        </div>
                                    </div>
                                </div>
                                <div className="pt-1">
                                    <XTanStackTable
                                        columns={columns}
                                        data={cars || []}
                                        loading={loading}
                                        pagination={pagination}
                                        onPaginationChange={setPagination}
                                        pageCount={meta?.last_page ?? 0}
                                        total={meta?.total}
                                        isBordered={true}
                                        theadClass="text-muted table-light"
                                        mobileMode={isMobile}
                                        onSortingChange={handleSortChange}
                                    />
                                </div>
                            </CardBody>
                        </Card>
                    </Col>
                </Row>
            </Container>
        </div>
    );
};

export default CarList;
