import { useEffect, useState, useMemo } from "react";

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
} from "reactstrap";
import classnames from "classnames";

// RangeSlider
import "nouislider/distribute/nouislider.css";

//redux
import { useSelector, useDispatch } from "react-redux";
import { Link } from "react-router-dom";
import { ToastContainer } from "react-toastify";
import { getCarsPaginate } from "slices/cars/thunk";
import XTanStackTable from "Components/Common/XTanStackTable";

const CarList = (props: any) => {
    const dispatch: any = useDispatch();

    const { cars, meta, loading } = useSelector(
        (state: any) => state.Car
    );

    // State
    const [activeTab, setActiveTab] = useState<any>("active");

    // Actions
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

            dispatch(
                getCarsPaginate({
                    page: pagination.pageIndex + 1,
                    perPage: pagination.pageSize,
                    companyId: obj.company_id,
                    status: activeTab
                })
            );
        }
    }, [dispatch, activeTab, pagination.pageIndex, pagination.pageSize]);

    const columns = useMemo(() => [
        {
            header: "Carro",
            accessorKey: "name",
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
            header: "Matrícula",
            accessorKey: "license_plate",
            enableColumnFilter: false,
        },
        {
            header: "Ação",
            cell: (cell: any) => {
                return (
                    <UncontrolledDropdown>
                        <DropdownToggle
                            href="#"
                            className="btn btn-soft-primary btn-sm"
                            tag="button"
                        >
                            <i className="ri-more-fill" />
                        </DropdownToggle>
                        <DropdownMenu className="dropdown-menu-end">
                            <DropdownItem href={`/cars/${cell.row.original.id}/show`}>
                                <i className="ri-eye-fill align-bottom me-2 text-muted"></i>{" "}
                                Visualizar
                            </DropdownItem>

                            <DropdownItem href={`/cars/${cell.row.original.id}`}>
                                <i className="ri-pencil-fill align-bottom me-2 text-muted"></i>{" "}
                                Editar
                            </DropdownItem>
                        </DropdownMenu>
                    </UncontrolledDropdown>
                );
            },
        },
    ],
        []
    );

    document.title = "Carros | Xplendor";

    return (
        <div className="page-content">
            <ToastContainer closeButton={false} limit={1} />
            <Container fluid>
                <Row>
                    <Col lg={12}>
                        <div>
                            <Card>
                                <CardHeader className="border-0">
                                    <div className="d-flex align-items-center">
                                        <h5 className="card-title mb-0 flex-grow-1">Carros</h5>
                                        <div className="flex-shrink-0">
                                            <div className="d-flex gap-2 flex-wrap">
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
                                    {cars && cars.length > 0 ? (
                                        <XTanStackTable
                                            columns={columns}
                                            data={(cars || [])}
                                            loading={loading}
                                            pagination={pagination}
                                            onPaginationChange={setPagination}
                                            pageCount={meta?.last_page ?? 0}
                                            total={meta?.total}
                                            isBordered={true}
                                            theadClass="text-muted table-light"
                                        />
                                    ) : (
                                        <div className="py-4 text-center">
                                            <div>
                                                <i className="ri-search-line display-5 text-dark"></i>
                                            </div>
                                            <div className="mt-4">
                                                <h5>Nenhum resultado encontrado!</h5>
                                            </div>
                                        </div>
                                    )}
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
