import { useEffect, useState, useMemo } from "react";
import { useIsMobile } from "../../hooks/useIsMobile";

import {
    Container,
    Row,
    Card,
    CardHeader,
    Col,
    UncontrolledDropdown,
    DropdownToggle,
    DropdownMenu,
    DropdownItem,
} from "reactstrap";

// RangeSlider
import "nouislider/distribute/nouislider.css";

//redux
import { useSelector, useDispatch } from "react-redux";
import { Link } from "react-router-dom";
import { ToastContainer, toast } from "react-toastify";
import { getUsersPaginate } from "slices/users/thunk";
import { startImpersonationFlow } from "helpers/impersonation";
import XTanStackTable from "Components/Common/XTanStackTable";
import { createSelector } from "reselect";
import { getWorkingCompanyId } from "helpers/workingCompany";
import { isPlatformRoot, isRootRole } from "helpers/roles";

const selectUserState = (state: any) => state.User;

const selectUsersListViewModel = createSelector(
    [selectUserState],
    (userState) => ({
        users: userState.data.users,
        meta: userState.data.meta,
        loading: userState.loading.list,
    })
);

export default function UsersList() {
    const dispatch: any = useDispatch();

    const { users, meta, loading } = useSelector(selectUsersListViewModel);

    const isMobile = useIsMobile(680);
    const [companyId, setCompanyId] = useState<any>(null);

    // Identidade atual (para o gatilho de impersonation — só root, e não sobre si nem sobre outro root).
    const me = useMemo(() => { try { return JSON.parse(sessionStorage.getItem("authUser") || "null"); } catch { return null; } }, []);
    const isRoot = isPlatformRoot(me);
    const enterAs = async (id: number) => {
        try { await startImpersonationFlow(id); } // recarrega a app na identidade do alvo
        catch (e: any) { toast.error(e?.message ?? "Não foi possível iniciar a impersonation."); }
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
            setCompanyId(getWorkingCompanyId());

            dispatch(
                getUsersPaginate({
                    page: pagination.pageIndex + 1,
                    perPage: pagination.pageSize,
                    companyId: getWorkingCompanyId(),
                })
            );
        }
    }, [dispatch, pagination.pageIndex, pagination.pageSize]);

    const columns = useMemo(() => [
        {
            header: "Nome",
            accessorKey: "name",
            enableColumnFilter: false,
            cell: (cell: any) => (
                <div className="d-flex align-items-center">
                    {cell.row.original.avatar && <img src={process.env.REACT_APP_PUBLIC_URL + "/storage/" + cell.row.original.avatar} alt="" className="rounded-circle avatar-xs material-shadow me-2"></img>}
                    <span>{cell.row.original.name}</span>
                </div>
            ),
        },
        {
            header: "Email",
            accessorKey: "email",
            enableColumnFilter: false,
        },
        {
            header: "Perfil",
            accessorKey: "role",
            enableColumnFilter: false,
        },
        {
            header: "Telemóvel",
            accessorKey: "mobile",
            enableColumnFilter: false,
            cell: (cell: any) => cell.row.original.mobile || "—",
        },
        {
            header: "WhatsApp",
            accessorKey: "whatsapp",
            enableColumnFilter: false,
            cell: (cell: any) => cell.row.original.whatsapp || "—",
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
                            <DropdownItem href={`/users/${cell.row.original.id}`}>
                                <i className="ri-pencil-fill align-bottom me-2 text-muted"></i>{" "}
                                Editar
                            </DropdownItem>
                            {isRoot && !isRootRole(cell.row.original.role) && cell.row.original.id !== me?.id && (
                                <DropdownItem onClick={() => enterAs(cell.row.original.id)}>
                                    <i className="ri-spy-line align-bottom me-2 text-muted"></i>{" "}
                                    Entrar como
                                </DropdownItem>
                            )}
                        </DropdownMenu>
                    </UncontrolledDropdown>
                );
            },
        },
    ],
        [isRoot, me]
    );

    document.title = "Colaboradores | Xplendor";

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
                                        <h5 className="card-title mb-0 flex-grow-1">Colaboradores</h5>
                                        <div className="flex-shrink-0">
                                            <div className="d-flex gap-2 flex-wrap">
                                                <Link to="/users/create" className="btn btn-outline-success">
                                                    <i className="ri-add-line align-bottom"></i>
                                                </Link>
                                            </div>
                                        </div>
                                    </div>
                                </CardHeader>
                                <div className="card-body pt-2">
                                    <XTanStackTable
                                        columns={columns}
                                        data={users || []}
                                        loading={loading}
                                        pagination={pagination}
                                        onPaginationChange={setPagination}
                                        pageCount={meta?.last_page ?? 0}
                                        total={meta?.total}
                                        isBordered={true}
                                        theadClass="text-muted table-light"
                                        mobileMode={isMobile}
                                    />
                                </div>
                            </Card>
                        </div>
                    </Col>
                </Row>
            </Container>
        </div>
    );
}
