import { useEffect, useState, useMemo, useCallback } from "react";

import {
    Container,
    Nav,
    NavItem,
    Row,
    Card,
    CardHeader,
    CardBody,
    Col,
    Label,
} from "reactstrap";
import classnames from "classnames";

// Select Form
import Select from "react-select";

// image
import easyDataIcon from "../../assets/images/icon-easydata.png";

//redux
import { useSelector, useDispatch } from "react-redux";
import { Link } from "react-router-dom";
import { toast, ToastContainer } from "react-toastify";
import XTanStackTable from "Components/Common/XTanStackTable";
import { createSelector } from "reselect";
// Slices
import { showCarmine, syncCarmine } from "slices/thunks";
import { getCarsPaginate } from "slices/cars/thunk";
import { getCarBrands } from "slices/car-brands/thunk";
import { getCarModels } from "slices/car-models/thunk";

const tabOptions = [
    { key: "active", label: "Ativos" },
    { key: "sold", label: "Vendidos" },
    { key: "available_soon", label: "Disponível Brevemente" },
    { key: "draft", label: "Em Rascunho" },
];

const getMetricCount = (items: any) => (Array.isArray(items) ? items.length : Number(items ?? 0));

const getAttentionBadge = (car: any) => {
    const views = getMetricCount(car.views);
    const leads = getMetricCount(car.leads);

    if (views > 500 && leads === 0) {
        return {
            label: "Sem conversao",
            className: "bg-warning-subtle text-warning",
            icon: "ri-error-warning-line",
        };
    }

    if (leads > 2) {
        return {
            label: "Bom desempenho",
            className: "bg-success-subtle text-success",
            icon: "ri-checkbox-circle-line",
        };
    }

    if (views < 50) {
        return {
            label: "Baixa visibilidade",
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

const formatCurrency = (value: number) =>
    new Intl.NumberFormat("pt-PT", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);

const formatConversionRate = (views: number, leads: number) => {
    if (!views) return "0%";
    const rate = (leads / views) * 100;
    return `${rate.toFixed(rate >= 1 ? 1 : 2)}%`;
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
    const [activeTab, setActiveTab] = useState<any>("active");
    const [isMobile, setIsMobile] = useState(window.innerWidth < 680);
    const [companyId, setCompanyId] = useState<any>(null);
    const [carBrandIds, setCarBrandIds] = useState<number[]>([]);
    const [carModelIds, setCarModelIds] = useState<number[]>([]);
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

    const toggleTab = (tab: any) => {
        if (activeTab !== tab) {
            setActiveTab(tab);
        }
    };

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
                    status: activeTab,
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
        activeTab,
        carBrandIds,
        carModelIds,
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
                        {car.images.length > 0 && (
                            <div className="flex-shrink-0 me-3">
                                <img
                                    src={process.env.REACT_APP_PUBLIC_URL + car.images[0].image}
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
            cell: (cell: any) => (
                <span className="fw-semibold text-body">
                    €{formatCurrency(cell.getValue())}
                </span>
            )
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
                            <i className="ri-brain-line me-1" />
                        </Link>
                        <Link
                            to={`/cars/${id}/marketing`}
                            className="btn btn-soft-warning btn-sm"
                            title="Marketing"
                        >
                            <i className="ri-megaphone-line me-1" />
                        </Link>
                        <Link
                            to={`/cars/${id}`}
                            className="btn btn-soft-primary btn-sm"
                            title="Editar"
                        >
                            <i className="ri-pencil-line me-1" />
                            Editar
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
                                        <h5 className="mb-1 fw-semibold">Viaturas acompanhadas por estado e desempenho</h5>
                                        <p className="text-muted fs-13 mb-0">
                                            Prioriza rapidamente os carros com mais procura, menos conversão ou menor visibilidade.
                                        </p>
                                    </div>
                                    <span className="badge bg-light text-muted fs-12 px-3 py-2">
                                        {meta?.total ?? 0} viatura{meta?.total === 1 ? "" : "s"}
                                    </span>
                                </div>
                                <Nav
                                    className="nav-tabs-custom card-header-tabs border-bottom-0 rounded-3 p-2"
                                    role="tablist"
                                    style={{
                                        background: "#f8f9fa",
                                        boxShadow: "inset 0 0 0 1px rgba(233,235,236,0.95)",
                                        gap: "0.35rem",
                                    }}
                                >
                                    {tabOptions.map((tab) => (
                                        <NavItem key={tab.key} className="flex-fill">
                                            <button
                                                type="button"
                                                className={classnames("nav-link w-100")}
                                                onClick={() => {
                                                    toggleTab(tab.key);
                                                }}
                                                style={{
                                                    border: activeTab === tab.key ? "1px solid rgba(64,81,137,0.12)" : "1px solid transparent",
                                                    borderBottom: "none",
                                                    borderRadius: "0.75rem",
                                                    background: activeTab === tab.key ? "#ffffff" : "transparent",
                                                    color: activeTab === tab.key ? "#405189" : "#878a99",
                                                    fontWeight: activeTab === tab.key ? 600 : 400,
                                                    padding: "14px 16px",
                                                    fontSize: "13px",
                                                    boxShadow: activeTab === tab.key ? "0 6px 18px rgba(15, 23, 42, 0.06)" : "none",
                                                    transition: "all 0.2s ease",
                                                }}
                                            >
                                                {tab.label}
                                                {activeTab === tab.key && (
                                                    <span className="badge bg-danger-subtle text-danger align-middle rounded-pill ms-2">
                                                        {meta?.total}
                                                    </span>
                                                )}
                                            </button>
                                        </NavItem>
                                    ))}
                                </Nav>
                            </CardHeader>
                            <CardBody className="pt-4">
                                <div className="mb-3 px-1">
                                    <div className="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                        <div>
                                            <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                                Prioridade Comercial
                                            </p>
                                            <h6 className="mb-0 fw-semibold">Que carros precisam de atenção agora?</h6>
                                        </div>
                                        <div className="d-flex gap-2 flex-wrap">
                                            <span className="badge bg-warning-subtle text-warning px-3 py-2 fs-12">Sem conversao</span>
                                            <span className="badge bg-success-subtle text-success px-3 py-2 fs-12">Bom desempenho</span>
                                            <span className="badge bg-secondary-subtle text-secondary px-3 py-2 fs-12">Baixa visibilidade</span>
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
