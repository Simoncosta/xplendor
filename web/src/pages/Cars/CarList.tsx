import React, { useEffect, useState, useMemo } from "react";

import {
    Container,
    UncontrolledDropdown,
    DropdownToggle,
    DropdownItem,
    DropdownMenu,
    Nav,
    NavItem,
    NavLink,
    UncontrolledCollapse,
    Row,
    Card,
    CardHeader,
    Col,
} from "reactstrap";
import classnames from "classnames";

// RangeSlider
import Nouislider from "nouislider-react";
import "nouislider/distribute/nouislider.css";

import TableContainer from "../../Components/Common/TableContainer";

//Import actions
// import { getProducts as onGetProducts, deleteProducts } from "../../slices/thunks";
import { isEmpty } from "lodash";
import Select from "react-select";

//redux
import { useSelector, useDispatch } from "react-redux";
import { Link } from "react-router-dom";
import { toast, ToastContainer } from "react-toastify";
import { createSelector } from "reselect";
import { getCarsPaginate } from "slices/cars/thunk";
import XTanStackTable from "Components/Common/XTanStackTable";
import XButton from "Components/Common/XButton";

const SingleOptions = [
    { value: 'Watches', label: 'Watches' },
    { value: 'Headset', label: 'Headset' },
    { value: 'Sweatshirt', label: 'Sweatshirt' },
    { value: '20% off', label: '20% off' },
    { value: '4 star', label: '4 star' },
];

const CarList = (props: any) => {
    const dispatch: any = useDispatch();

    // Redux direto, sem reselect desnecessário
    const { cars, meta, loading } = useSelector(
        (state: any) => state.Car
    );

    // State
    const [activeTab, setActiveTab] = useState<any>("1");

    // Actions
    const toggleTab = (tab: any, type: any) => {
        if (activeTab !== tab) {
            setActiveTab(tab);
            // let filteredProducts = products;
            // if (type !== "all") {
            //     filteredProducts = products.filter((product: any) => product.status === type);
            // }
            // setProductList(filteredProducts);
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
                })
            );
        }
    }, [dispatch, pagination.pageIndex, pagination.pageSize]);

    const columns = useMemo(() => [
        {
            header: "#",
            accessorKey: "id",
            enableColumnFilter: false,
            enableSorting: false,
            cell: (cell: any) => {
                return <input type="checkbox" className="productCheckBox form-check-input" value={cell.getValue()} />;
            },
        },
        {
            header: "Carro",
            accessorKey: "name",
            enableColumnFilter: false,
            cell: (cell: any) => (
                <>
                    <div className="d-flex align-items-center">
                        {cell.row.original.images.length > 0 && (
                            <div className="flex-shrink-0 me-3">
                                <div className="avatar-md bg-light rounded p-1">
                                    <img
                                        src={process.env.REACT_APP_PUBLIC_URL + cell.row.original.images[0].image}
                                        alt=""
                                        className="img-fluid d-block"
                                    />
                                </div>
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
                <span>€{cell.getValue()}</span>
            )
        },
        {
            header: "Matrícula",
            accessorKey: "license_plate",
            enableColumnFilter: false,
        },
        {
            header: "Action",
            cell: (cell: any) => {
                return (
                    <UncontrolledDropdown>
                        <DropdownToggle
                            href="#"
                            className="btn btn-soft-secondary btn-sm"
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

                            {/* <DropdownItem divider /> */}
                            {/* <DropdownItem
                                href="#"
                                onClick={() => {
                                    const productData = cell.row.original;
                                    onClickDelete(productData);
                                }}
                            >
                                <i className="ri-delete-bin-fill align-bottom me-2 text-muted"></i>{" "}
                                Delete
                            </DropdownItem> */}
                        </DropdownMenu>
                    </UncontrolledDropdown>
                );
            },
        },
    ],
        [cars]
    );
    document.title = "Carros | Xplendor";

    return (
        <div className="page-content">
            <ToastContainer closeButton={false} limit={1} />

            {/* <DeleteModal
                show={deleteModal}
                onDeleteClick={handleDeleteProduct}
                onCloseClick={() => setDeleteModal(false)}
            />
            <DeleteModal
                show={deleteModalMulti}
                onDeleteClick={() => {
                    deleteMultiple();
                    setDeleteModalMulti(false);
                }}
                onCloseClick={() => setDeleteModalMulti(false)}
            /> */}
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
                                        {/* <Link to="#" className="text-decoration-underline">
                                            Clear All
                                        </Link> */}
                                    </div>
                                </div>

                                <div className="filter-choices-input">
                                    {/* <Select
                                        value={selectedMulti}
                                        isMulti={true}
                                        onChange={(selectedMulti: any) => {
                                            handleMulti(selectedMulti);
                                        }}
                                        options={SingleOptions}
                                    /> */}
                                </div>
                            </CardHeader>

                            {/* <div className="accordion accordion-flush">
                                <div className="card-body border-bottom">
                                    <div>
                                        <p className="text-muted text-uppercase fs-12 fw-medium mb-2">
                                            Products
                                        </p>
                                        <ul className="list-unstyled mb-0 filter-list">
                                            <li>
                                                <Link to="#" className={cate === "Kitchen Storage & Containers" ? "active d-flex py-1 align-items-center" : "d-flex py-1 align-items-center"} onClick={() => categories("Kitchen Storage & Containers")}>
                                                    <div className="flex-grow-1">
                                                        <h5 className="fs-13 mb-0 listname">Grocery</h5>
                                                    </div>
                                                </Link>
                                            </li>
                                            <li>
                                                <Link to="#" className={cate === "Clothes" ? "active d-flex py-1 align-items-center" : "d-flex py-1 align-items-center"} onClick={() => categories("Clothes")}>
                                                    <div className="flex-grow-1">
                                                        <h5 className="fs-13 mb-0 listname">Fashion</h5>
                                                    </div>
                                                    <div className="flex-shrink-0 ms-2">
                                                        <span className="badge bg-light text-muted">5</span>
                                                    </div>
                                                </Link>
                                            </li>
                                            <li>
                                                <Link to="#" className={cate === "Watches" ? "active d-flex py-1 align-items-center" : "d-flex py-1 align-items-center"} onClick={() => categories("Watches")}>
                                                    <div className="flex-grow-1">
                                                        <h5 className="fs-13 mb-0 listname">Watches</h5>
                                                    </div>
                                                </Link>
                                            </li>
                                            <li>
                                                <Link to="#" className={cate === "electronics" ? "active d-flex py-1 align-items-center" : "d-flex py-1 align-items-center"} onClick={() => categories("electronics")}>
                                                    <div className="flex-grow-1">
                                                        <h5 className="fs-13 mb-0 listname">Electronics</h5>
                                                    </div>
                                                    <div className="flex-shrink-0 ms-2">
                                                        <span className="badge bg-light text-muted">5</span>
                                                    </div>
                                                </Link>
                                            </li>
                                            <li>
                                                <Link to="#" className={cate === "Furniture" ? "active d-flex py-1 align-items-center" : "d-flex py-1 align-items-center"} onClick={() => categories("Furniture")}>
                                                    <div className="flex-grow-1">
                                                        <h5 className="fs-13 mb-0 listname">Furniture</h5>
                                                    </div>
                                                    <div className="flex-shrink-0 ms-2">
                                                        <span className="badge bg-light text-muted">6</span>
                                                    </div>
                                                </Link>
                                            </li>
                                            <li>
                                                <Link to="#" className={cate === "Bike Accessories" ? "active d-flex py-1 align-items-center" : "d-flex py-1 align-items-center"} onClick={() => categories("Bike Accessories")}>
                                                    <div className="flex-grow-1">
                                                        <h5 className="fs-13 mb-0 listname">Automotive Accessories</h5>
                                                    </div>
                                                </Link>
                                            </li>
                                            <li>
                                                <Link to="#" className={cate === "appliances" ? "active d-flex py-1 align-items-center" : "d-flex py-1 align-items-center"} onClick={() => categories("appliances")}>
                                                    <div className="flex-grow-1">
                                                        <h5 className="fs-13 mb-0 listname">Appliances</h5>
                                                    </div>
                                                    <div className="flex-shrink-0 ms-2">
                                                        <span className="badge bg-light text-muted">7</span>
                                                    </div>
                                                </Link>
                                            </li>
                                            <li>
                                                <Link to="#" className={cate === "Bags, Wallets and Luggage" ? "active d-flex py-1 align-items-center" : "d-flex py-1 align-items-center"} onClick={() => categories("Bags, Wallets and Luggage")} >
                                                    <div className="flex-grow-1">
                                                        <h5 className="fs-13 mb-0 listname">Kids</h5>
                                                    </div>
                                                </Link>
                                            </li>
                                        </ul>
                                    </div>
                                </div>

                                <div className="card-body border-bottom">
                                    <p className="text-muted text-uppercase fs-12 fw-medium mb-4">
                                        Price
                                    </p>
                                    <Nouislider range={{ min: 0, max: 100 }} start={[20, 80]} connect />

                                    <div className="formCost d-flex gap-2 align-items-center mt-3">
                                        <input className="form-control form-control-sm" type="text" value={`$ ${mincost}`} onChange={(e: any) => setMincost(e.target.value)} id="minCost" readOnly />
                                        <span className="fw-semibold text-muted">to</span>
                                        <input className="form-control form-control-sm" type="text" value={`$ ${maxcost}`} onChange={(e: any) => setMaxcost(e.target.value)} id="maxCost" readOnly />
                                    </div>
                                </div>

                                <div className="accordion-item">
                                    <h2 className="accordion-header">
                                        <button
                                            className="accordion-button bg-transparent shadow-none"
                                            type="button"
                                            id="flush-headingBrands"
                                        >
                                            <span className="text-muted text-uppercase fs-12 fw-medium">
                                                Brands
                                            </span>{" "}
                                            <span className="badge bg-success rounded-pill align-middle ms-1">
                                                2
                                            </span>
                                        </button>
                                    </h2>
                                    <UncontrolledCollapse
                                        toggler="#flush-headingBrands"
                                        defaultOpen
                                    >
                                        <div
                                            id="flush-collapseBrands"
                                            className="accordion-collapse collapse show"
                                            aria-labelledby="flush-headingBrands"
                                        >
                                            <div className="accordion-body text-body pt-0">
                                                <div className="search-box search-box-sm">
                                                    <input
                                                        type="text"
                                                        className="form-control bg-light border-0"
                                                        placeholder="Search Brands..."
                                                    />
                                                    <i className="ri-search-line search-icon"></i>
                                                </div>
                                                <div className="d-flex flex-column gap-2 mt-3">
                                                    <div className="form-check">
                                                        <input
                                                            className="form-check-input"
                                                            type="checkbox"
                                                            id="productBrandRadio5"
                                                            defaultChecked
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor="productBrandRadio5"
                                                        >
                                                            Boat
                                                        </label>
                                                    </div>
                                                    <div className="form-check">
                                                        <input
                                                            className="form-check-input"
                                                            type="checkbox"
                                                            id="productBrandRadio4"
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor="productBrandRadio4"
                                                        >
                                                            OnePlus
                                                        </label>
                                                    </div>
                                                    <div className="form-check">
                                                        <input
                                                            className="form-check-input"
                                                            type="checkbox"
                                                            id="productBrandRadio3"
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor="productBrandRadio3"
                                                        >
                                                            Realme
                                                        </label>
                                                    </div>
                                                    <div className="form-check">
                                                        <input
                                                            className="form-check-input"
                                                            type="checkbox"
                                                            id="productBrandRadio2"
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor="productBrandRadio2"
                                                        >
                                                            Sony
                                                        </label>
                                                    </div>
                                                    <div className="form-check">
                                                        <input
                                                            className="form-check-input"
                                                            type="checkbox"
                                                            id="productBrandRadio1"
                                                            defaultChecked
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor="productBrandRadio1"
                                                        >
                                                            JBL
                                                        </label>
                                                    </div>

                                                    <div>
                                                        <button
                                                            type="button"
                                                            className="btn btn-link text-decoration-none text-uppercase fw-medium p-0"
                                                        >
                                                            1,235 More
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </UncontrolledCollapse>
                                </div>

                                <div className="accordion-item">
                                    <h2 className="accordion-header">
                                        <button
                                            className="accordion-button bg-transparent shadow-none collapsed"
                                            type="button"
                                            id="flush-headingDiscount"
                                        >
                                            <span className="text-muted text-uppercase fs-12 fw-medium">
                                                Discount
                                            </span>{" "}
                                            <span className="badge bg-success rounded-pill align-middle ms-1">
                                                1
                                            </span>
                                        </button>
                                    </h2>
                                    <UncontrolledCollapse toggler="#flush-headingDiscount">
                                        <div
                                            id="flush-collapseDiscount"
                                            className="accordion-collapse collapse show"
                                        >
                                            <div className="accordion-body text-body pt-1">
                                                <div className="d-flex flex-column gap-2">
                                                    <div className="form-check">
                                                        <input
                                                            className="form-check-input"
                                                            type="checkbox"
                                                            id="productdiscountRadio6"
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor="productdiscountRadio6"
                                                        >
                                                            50% or more
                                                        </label>
                                                    </div>
                                                    <div className="form-check">
                                                        <input
                                                            className="form-check-input"
                                                            type="checkbox"
                                                            id="productdiscountRadio5"
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor="productdiscountRadio5"
                                                        >
                                                            40% or more
                                                        </label>
                                                    </div>
                                                    <div className="form-check">
                                                        <input
                                                            className="form-check-input"
                                                            type="checkbox"
                                                            id="productdiscountRadio4"
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor="productdiscountRadio4"
                                                        >
                                                            30% or more
                                                        </label>
                                                    </div>
                                                    <div className="form-check">
                                                        <input
                                                            className="form-check-input"
                                                            type="checkbox"
                                                            id="productdiscountRadio3"
                                                            defaultChecked
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor="productdiscountRadio3"
                                                        >
                                                            20% or more
                                                        </label>
                                                    </div>
                                                    <div className="form-check">
                                                        <input
                                                            className="form-check-input"
                                                            type="checkbox"
                                                            id="productdiscountRadio2"
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor="productdiscountRadio2"
                                                        >
                                                            10% or more
                                                        </label>
                                                    </div>
                                                    <div className="form-check">
                                                        <input
                                                            className="form-check-input"
                                                            type="checkbox"
                                                            id="productdiscountRadio1"
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor="productdiscountRadio1"
                                                        >
                                                            Less than 10%
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </UncontrolledCollapse>
                                </div>

                                <div className="accordion-item">
                                    <h2 className="accordion-header">
                                        <button
                                            className="accordion-button bg-transparent shadow-none collapsed"
                                            type="button"
                                            id="flush-headingRating"
                                        >
                                            <span className="text-muted text-uppercase fs-12 fw-medium">
                                                Rating
                                            </span>{" "}
                                            <span className="badge bg-success rounded-pill align-middle ms-1">
                                                1
                                            </span>
                                        </button>
                                    </h2>

                                    <UncontrolledCollapse toggler="#flush-headingRating">
                                        <div
                                            id="flush-collapseRating"
                                            className="accordion-collapse collapse show"
                                            aria-labelledby="flush-headingRating"
                                        >
                                            <div className="accordion-body text-body">
                                                <div className="d-flex flex-column gap-2">
                                                    <div className="form-check">
                                                        <input
                                                            className="form-check-input"
                                                            type="checkbox"
                                                            id="productratingRadio4"
                                                            onChange={e => {
                                                                if (e.target.checked) {
                                                                    onChangeRating(4);
                                                                } else {
                                                                    onUncheckMark(4);
                                                                }
                                                            }}
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor="productratingRadio4"
                                                        >
                                                            <span className="text-muted">
                                                                <i className="mdi mdi-star text-warning"></i>
                                                                <i className="mdi mdi-star text-warning"></i>
                                                                <i className="mdi mdi-star text-warning"></i>
                                                                <i className="mdi mdi-star text-warning"></i>
                                                                <i className="mdi mdi-star"></i>
                                                            </span>{" "}
                                                            4 & Above
                                                        </label>
                                                    </div>
                                                    <div className="form-check">
                                                        <input
                                                            className="form-check-input"
                                                            type="checkbox"
                                                            id="productratingRadio3"
                                                            onChange={e => {
                                                                if (e.target.checked) {
                                                                    onChangeRating(3);
                                                                } else {
                                                                    onUncheckMark(3);
                                                                }
                                                            }}
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor="productratingRadio3"
                                                        >
                                                            <span className="text-muted">
                                                                <i className="mdi mdi-star text-warning"></i>
                                                                <i className="mdi mdi-star text-warning"></i>
                                                                <i className="mdi mdi-star text-warning"></i>
                                                                <i className="mdi mdi-star"></i>
                                                                <i className="mdi mdi-star"></i>
                                                            </span>{" "}
                                                            3 & Above
                                                        </label>
                                                    </div>
                                                    <div className="form-check">
                                                        <input
                                                            className="form-check-input"
                                                            type="checkbox"
                                                            id="productratingRadio2"
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor="productratingRadio2"
                                                            onChange={(e: any) => {
                                                                if (e.target.checked) {
                                                                    onChangeRating(2);
                                                                } else {
                                                                    onUncheckMark(2);
                                                                }
                                                            }}
                                                        >
                                                            <span className="text-muted">
                                                                <i className="mdi mdi-star text-warning"></i>
                                                                <i className="mdi mdi-star text-warning"></i>
                                                                <i className="mdi mdi-star"></i>
                                                                <i className="mdi mdi-star"></i>
                                                                <i className="mdi mdi-star"></i>
                                                            </span>{" "}
                                                            2 & Above
                                                        </label>
                                                    </div>
                                                    <div className="form-check">
                                                        <input
                                                            className="form-check-input"
                                                            type="checkbox"
                                                            id="productratingRadio1"
                                                            onChange={e => {
                                                                if (e.target.checked) {
                                                                    onChangeRating(1);
                                                                } else {
                                                                    onUncheckMark(1);
                                                                }
                                                            }}
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor="productratingRadio1"
                                                        >
                                                            <span className="text-muted">
                                                                <i className="mdi mdi-star text-warning"></i>
                                                                <i className="mdi mdi-star"></i>
                                                                <i className="mdi mdi-star"></i>
                                                                <i className="mdi mdi-star"></i>
                                                                <i className="mdi mdi-star"></i>
                                                            </span>{" "}
                                                            1
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </UncontrolledCollapse>
                                </div>
                            </div> */}
                        </Card>
                    </Col>

                    <Col xl={9} lg={8}>
                        <div>
                            <Card>
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
                                                            { active: activeTab === "1" },
                                                            "fw-semibold"
                                                        )}
                                                        onClick={() => {
                                                            toggleTab("1", "all");
                                                        }}
                                                        href="#"
                                                    >
                                                        Ativos{" "}
                                                        <span className="badge bg-danger-subtle text-danger align-middle rounded-pill ms-1">
                                                            {cars?.length}
                                                        </span>
                                                    </NavLink>
                                                </NavItem>
                                                <NavItem>
                                                    <NavLink
                                                        className={classnames(
                                                            { active: activeTab === "2" },
                                                            "fw-semibold"
                                                        )}
                                                        onClick={() => {
                                                            toggleTab("2", "published");
                                                        }}
                                                        href="#"
                                                    >
                                                        Vendidos{" "}
                                                        <span className="badge bg-danger-subtle text-danger align-middle rounded-pill ms-1">
                                                            0
                                                        </span>
                                                    </NavLink>
                                                </NavItem>
                                                <NavItem>
                                                    <NavLink
                                                        className={classnames(
                                                            { active: activeTab === "3" },
                                                            "fw-semibold"
                                                        )}
                                                        onClick={() => {
                                                            toggleTab("3", "draft");
                                                        }}
                                                        href="#"
                                                    >
                                                        Em Rascunho
                                                    </NavLink>
                                                </NavItem>
                                            </Nav>
                                        </Col>
                                        {/* <div className="col-auto">
                                            <div id="selection-element">
                                                <div className="my-n1 d-flex align-items-center text-muted">
                                                    Select{" "}
                                                    <div
                                                        id="select-content"
                                                        className="text-body fw-semibold px-1"
                                                    >{dele}</div>{" "}
                                                    Result{" "}
                                                    <button
                                                        type="button"
                                                        className="btn btn-link link-danger p-0 ms-3"
                                                        onClick={() => setDeleteModalMulti(true)}
                                                    >
                                                        Remove
                                                    </button>
                                                </div>
                                            </div>
                                        </div> */}
                                    </Row>
                                </div>
                                <div className="card-body pt-0">
                                    <CardHeader className="border-0">
                                        <div className="d-flex align-items-center">
                                            <h5 className="card-title mb-0 flex-grow-1"></h5>
                                            <div className="flex-shrink-0">
                                                <div className="d-flex gap-2 flex-wrap">
                                                    <Link to="/cars/create" className="btn btn-outline-success">
                                                        <i className="ri-add-line align-bottom"></i>
                                                    </Link>
                                                </div>
                                            </div>
                                        </div>
                                    </CardHeader>
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
                                                <i className="ri-search-line display-5 text-success"></i>
                                            </div>

                                            <div className="mt-4">
                                                <h5>Sorry! No Result Found</h5>
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
