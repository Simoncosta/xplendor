import { useEffect, useState, useMemo, useCallback } from "react";

import {
    Container,
    UncontrolledDropdown,
    DropdownToggle,
    DropdownItem,
    DropdownMenu,
    Nav,
    NavItem,
    NavLink,
    Row,
    Card,
    CardHeader,
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
// Slices
import { showCarmine, syncCarmine } from "slices/thunks";
import { getCarsPaginate } from "slices/cars/thunk";
import { getCarBrands } from "slices/car-brands/thunk";
import { getCarModels } from "slices/car-models/thunk";

const CarList = (props: any) => {
    const dispatch: any = useDispatch();

    const { carmine, loading: loadingCarmine } = useSelector(
        (state: any) => state.Carmine
    );

    const { brands, loading: loadingCarBrands } = useSelector(
        (state: any) => state.CarBrand
    );

    const { models, loading: loadingCarModels } = useSelector(
        (state: any) => state.CarModel
    );

    const { cars, meta, loading } = useSelector(
        (state: any) => state.Car
    );

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

    const toggleTab = (tab: any, type: any) => {
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
            cell: (cell: any) => (
                <>
                    <div className="d-flex align-items-center">
                        {cell.row.original.images.length > 0 && (
                            <div className="flex-shrink-0 me-3">
                                <img
                                    src={process.env.REACT_APP_PUBLIC_URL + cell.row.original.images[0].image}
                                    alt=""
                                    className="img-thumbnail"
                                    width={150}
                                />
                            </div>
                        )}
                        <div className="flex-grow-1">
                            <h5 className="fs-14 mb-1">
                                <Link
                                    to="/apps-ecommerce-product-details"
                                    className="text-body"
                                >
                                    {cell.row.original.brand.name}
                                </Link>
                            </h5>
                            <p className="text-muted mb-0">
                                <span className="fw-medium">
                                    {cell.row.original.model.name}
                                </span>
                            </p>
                            <p className="text-muted mb-0">
                                <span className="fw-medium">
                                    {cell.row.original.license_plate}
                                </span>
                            </p>
                        </div>
                    </div>
                </>
            ),
        },
        {
            header: "Preço",
            accessorKey: "price_gross",
            enableColumnFilter: false,
            cell: (cell: any) => (
                <span>
                    €{new Intl.NumberFormat("pt-PT", {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }).format(cell.getValue())}
                </span>
            )
        },
        {
            header: "Views",
            accessorKey: "views",
            enableColumnFilter: false,
            cell: (cell: any) => (
                <span className="text-muted">{cell.row.original.views.length > 0 ? cell.row.original.views.length : 0}</span>
            )
        },
        {
            header: "Leads",
            accessorKey: "leads",
            enableColumnFilter: false,
            cell: (cell: any) => (
                <span className="text-muted">{cell.row.original.leads.length > 0 ? cell.row.original.leads.length : 0}</span>
            )
        },
        {
            header: "Interações",
            accessorKey: "interactions",
            enableColumnFilter: false,
            cell: (cell: any) => (
                <span className="text-muted">{cell.row.original.interactions.length > 0 ? cell.row.original.interactions.length : 0}</span>
            )
        },
        {
            header: "Criado em",
            accessorKey: "car_created_at",
            enableColumnFilter: false,
            cell: (cell: any) => (
                <>
                    <span className="text-muted">{new Date(cell.row.original.car_created_at ?? cell.row.original.created_at).toLocaleDateString("pt-PT", {
                        month: "long",
                        day: "numeric",
                        year: "numeric",
                    })}</span>
                    <span>{cell.row.original.is_resume ? " (Retoma)" : ""}</span>
                </>
            )
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
                            to={`/cars/${id}/marketing`}
                            className="btn btn-soft-warning btn-sm"
                            title="Marketing"
                        >
                            <i className="ri-megaphone-line" />
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
                <Row>
                    <Col xl={3} lg={4}>
                        <Card>
                            <CardHeader >
                                <div className="d-flex mb-3">
                                    <div className="flex-grow-1">
                                        <h5 className="fs-16">Filtros</h5>
                                    </div>
                                    <div className="flex-shrink-0">
                                        <a href="#" onClick={() => {
                                            setCarBrandIds([]);
                                            setCarModelIds([]);
                                            setMincost(undefined);
                                            setMaxcost(undefined);
                                            setSort({
                                                field: null,
                                                direction: null,
                                            })
                                        }} className="text-decoration-underline">
                                            Limpar todos
                                        </a>
                                    </div>
                                </div>
                                <div className="filter-choices-input mb-3">
                                    <Label for="car_brand_id">Marca</Label>
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
                                    <Label for="car_model_id">Modelo</Label>
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
                                    <Label for="car_model_id">Preço</Label>
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
                            </CardHeader>
                        </Card>
                    </Col>

                    <Col xl={9} lg={8}>
                        <div>
                            <Card>
                                <CardHeader className="border-0">
                                    <div className="d-flex align-items-center">
                                        <h5 className="card-title mb-0 flex-grow-1">Carros</h5>
                                        <div className="flex-shrink-0">
                                            <div className="d-flex gap-2 flex-wrap">
                                                {carmine && carmine.id && (
                                                    <button onClick={onClickSyncCarmine} className="btn btn-outline-danger">
                                                        <img src={easyDataIcon} alt="EasyData" width={10} />
                                                    </button>
                                                )}
                                                <Link to="/cars/create" className="btn btn-outline-success">
                                                    <i className="ri-add-line align-bottom"></i>
                                                </Link>
                                            </div>
                                        </div>
                                    </div>
                                </CardHeader>
                                <div className="card-header border-0">
                                    <Row className=" align-items-center">
                                        <Col>
                                            <Nav
                                                className="nav-tabs-custom card-header-tabs border-bottom-0"
                                                role="tablist"
                                            >
                                                <NavItem>
                                                    <NavLink
                                                        className={classnames(
                                                            { active: activeTab === "active" },
                                                            "fw-semibold"
                                                        )}
                                                        onClick={() => {
                                                            toggleTab("active", "active");
                                                        }}
                                                        href="#"
                                                    >
                                                        Ativos{" "}
                                                        {
                                                            activeTab === 'active' && (
                                                                <span className="badge bg-danger-subtle text-danger align-middle rounded-pill ms-1">
                                                                    {meta?.total}
                                                                </span>
                                                            )
                                                        }
                                                    </NavLink>
                                                </NavItem>
                                                <NavItem>
                                                    <NavLink
                                                        className={classnames(
                                                            { active: activeTab === "sold" },
                                                            "fw-semibold"
                                                        )}
                                                        onClick={() => {
                                                            toggleTab("sold", "sold");
                                                        }}
                                                        href="#"
                                                    >
                                                        Vendidos{" "}
                                                        {
                                                            activeTab === 'sold' && (
                                                                <span className="badge bg-danger-subtle text-danger align-middle rounded-pill ms-1">
                                                                    {meta?.total}
                                                                </span>
                                                            )
                                                        }
                                                    </NavLink>
                                                </NavItem>
                                                <NavItem>
                                                    <NavLink
                                                        className={classnames(
                                                            { active: activeTab === "available_soon" },
                                                            "fw-semibold"
                                                        )}
                                                        onClick={() => {
                                                            toggleTab("available_soon", "available_soon");
                                                        }}
                                                        href="#"
                                                    >
                                                        Disponível Brevemente{" "}
                                                        {
                                                            activeTab === 'available_soon' && (
                                                                <span className="badge bg-danger-subtle text-danger align-middle rounded-pill ms-1">
                                                                    {meta?.total}
                                                                </span>
                                                            )
                                                        }
                                                    </NavLink>
                                                </NavItem>
                                                <NavItem>
                                                    <NavLink
                                                        className={classnames(
                                                            { active: activeTab === "draft" },
                                                            "fw-semibold"
                                                        )}
                                                        onClick={() => {
                                                            toggleTab("draft", "draft");
                                                        }}
                                                        href="#"
                                                    >
                                                        Em Rascunho{" "}
                                                        {
                                                            activeTab === 'draft' && (
                                                                <span className="badge bg-danger-subtle text-danger align-middle rounded-pill ms-1">
                                                                    {meta?.total}
                                                                </span>
                                                            )
                                                        }
                                                    </NavLink>
                                                </NavItem>
                                            </Nav>
                                        </Col>
                                    </Row>
                                </div>
                                <div className="card-body pt-2">
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
                            </Card>
                        </div>
                    </Col>
                </Row>
            </Container>
        </div>
    );
};

export default CarList;
