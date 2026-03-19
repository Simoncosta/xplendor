// React
import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useDispatch, useSelector } from 'react-redux';
// Components
import { Card, CardBody, CardHeader, Col, Container, Row } from 'reactstrap';
import { ToastContainer } from 'react-toastify';
import XTanStackTable from 'Components/Common/XTanStackTable';
import { createSelector } from 'reselect';
// Slices
import { getCompaniesPaginate } from 'slices/companies/thunk';

const selectCompanyState = (state: any) => state.Company;

const selectCompanyListViewModel = createSelector(
    [selectCompanyState],
    (companyState: any) => ({
        companies: companyState.data.companies,
        meta: companyState.data.meta,
        loading: companyState.loading.list,
    })
);

const CompanyList = () => {
    const dispatch: any = useDispatch();
    document.title = "Empresas | Xplendor";

    // Redux direto, sem reselect desnecessário
    const { companies, meta, loading } = useSelector(selectCompanyListViewModel);

    // Paginação controlada no pai (server-side)
    const [pagination, setPagination] = useState({
        pageIndex: 0,
        pageSize: 10,
    });

    // Fetch sempre que mudar página ou tamanho
    useEffect(() => {
        dispatch(
            getCompaniesPaginate({
                page: pagination.pageIndex + 1,
                perPage: pagination.pageSize,
            })
        );
    }, [dispatch, pagination.pageIndex, pagination.pageSize]);

    const columns = useMemo(
        () => [
            {
                header: "Nome",
                cell: (cellProps: any) => {
                    return (
                        <div className="d-flex align-items-center">
                            {
                                cellProps.row.original.logo_path && (
                                    <div className="flex-shrink-0 me-2">
                                        <img src={process.env.REACT_APP_PUBLIC_URL + cellProps.row.original.logo_path} alt="" className="avatar-xs rounded-circle" />
                                    </div>
                                )
                            }
                            {cellProps.row.original.fiscal_name}
                        </div>
                    )
                },
                accessorKey: "fiscal_name",
                enableColumnFilter: false,
            },
            {
                header: "NIF",
                accessorKey: "nipc",
                enableColumnFilter: false,
            },
            {
                header: "Ação",
                cell: (cellProps: any) => {
                    return (
                        <Link to={`/companies/${cellProps.row.original.id}`} className="">
                            <i className="ri-eye-line align-bottom"></i>
                        </Link>
                    )
                }
            },
        ],
        []
    );

    return (
        <React.Fragment>
            <div className="page-content">
                <Container fluid>
                    <Row>
                        <Col lg={12}>
                            <Card id="companyList">
                                <CardHeader className="border-0">
                                    <div className="d-flex align-items-center">
                                        <h5 className="card-title mb-0 flex-grow-1">Empresas</h5>
                                        <div className="flex-shrink-0">
                                            <div className="d-flex gap-2 flex-wrap">
                                                <Link to="/companies/create" className="btn btn-outline-success">
                                                    <i className="ri-add-line align-bottom"></i>
                                                </Link>
                                            </div>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardBody className="pt-0">
                                    <div>
                                        <XTanStackTable
                                            columns={(columns || [])}
                                            data={(companies || [])}
                                            loading={loading}
                                            pagination={pagination}
                                            onPaginationChange={setPagination}
                                            pageCount={meta?.last_page ?? 0}
                                            total={meta?.total}
                                            SearchPlaceholder='Pesquisar...'
                                            isBordered={true}
                                            theadClass="text-muted table-light"
                                        />
                                        <ToastContainer closeButton={false} limit={1} />
                                    </div>
                                </CardBody>
                            </Card>
                        </Col>
                    </Row>
                </Container>
            </div>
        </React.Fragment >
    )
};

export default CompanyList;
