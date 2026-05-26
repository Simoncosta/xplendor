// React
import { useCallback, useEffect, useMemo, useState } from "react";
import { useIsMobile } from "../../hooks/useIsMobile";
// Redux
import { useDispatch, useSelector } from "react-redux";
// Components
import XTanStackTable from "Components/Common/XTanStackTable";
import LeadStatusBadge from "./components/LeadStatusBadge";
import {
    Container,
    Row,
    Card,
    CardHeader,
    Col,
} from "reactstrap";
// Slices
import { getLeadsPaginate } from "slices/thunks";
import { updateLeadStatus } from "slices/leads/thunk";
import { createSelector } from "reselect";

const formatTimeDiff = (dateStr: string): string => {
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 60) return `${diff}s`;
    if (diff < 3600) return `${Math.floor(diff / 60)}m`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
    return `${Math.floor(diff / 86400)}d`;
};

const selectLeadState = (state: any) => state.Lead;


const selectLeadListViewModel = createSelector(
    [selectLeadState],
    (leadState) => ({
        leads: leadState.data.leads,
        meta: leadState.data.meta,
        loading: leadState.loading.list,
        loadingUpdate: leadState.loadingUpdate,
    })
);

export default function LeadList() {
    const dispatch: any = useDispatch();
    const isMobile = useIsMobile(680);

    const { leads, meta, loading, loadingUpdate } = useSelector(selectLeadListViewModel);

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
                getLeadsPaginate({
                    page: pagination.pageIndex + 1,
                    perPage: pagination.pageSize,
                    companyId: obj.company_id,
                })
            );
        }
    }, [dispatch, pagination.pageIndex, pagination.pageSize]);

    const handleStatusChange = useCallback((leadId: number, status: string) => {
        dispatch(updateLeadStatus({ leadId, status }));
    }, [dispatch]);

    const columns = useMemo(() => [
        {
            enableColumnFilter: false,
            header: "Cliente",
            accessorKey: "name",
            cell: ({ row }: any) => {
                const lead = row.original;

                return (
                    <div>
                        <h6 className="mb-0">{lead.name}</h6>
                        <small className="text-muted d-block">{lead.phone || "—"}</small>
                        <small className="text-muted">{lead.email}</small>
                    </div>
                );
            },
        },
        {
            enableColumnFilter: false,
            header: "Carro",
            accessorKey: "car",
            cell: ({ row }: any) => {
                const car = row.original.car;

                const image = car?.images?.find((img: any) => img.is_primary)?.image;

                return (
                    <div className="d-flex align-items-center gap-2">
                        {image && (
                            <img
                                src={process.env.REACT_APP_PUBLIC_URL + image}
                                alt=""
                                className="rounded"
                                style={{ width: 50, height: 35, objectFit: "cover" }}
                            />
                        )}
                        <div>
                            <h6 className="mb-0">
                                {car?.brand?.name} {car?.model?.name}
                            </h6>
                            <small className="text-muted">{car?.version}</small>
                        </div>
                    </div>
                );
            },
        },
        {
            enableColumnFilter: false,
            header: "Estado",
            accessorKey: "status",
            cell: ({ row }: any) => {
                const lead = row.original;

                return (
                    <LeadStatusBadge
                        currentStatus={lead.status}
                        onChange={(newStatus) => handleStatusChange(lead.id, newStatus)}
                        disabled={loadingUpdate}
                        size="sm"
                    />
                );
            },
        },
        {
            enableColumnFilter: false,
            header: "Tempo",
            accessorKey: "created_at",
            cell: ({ row }: any) => formatTimeDiff(row.original.created_at),
        },
        {
            enableColumnFilter: false,
            header: "Origem",
            accessorKey: "channel",
            cell: ({ row }: any) => {
                const lead = row.original;

                return (
                    <span className="text-muted">
                        {lead.channel} - {lead.utm_source}
                    </span>
                );
            },
        },
        {
            enableColumnFilter: false,
            header: "Ações",
            id: "actions",
            cell: ({ row }: any) => {
                const lead = row.original;

                const phone = lead.phone?.replace(/\D/g, "");

                return (
                    <div className="d-flex gap-2">
                        {phone && (
                            <>
                                <a
                                    href={`tel:${phone}`}
                                    className="btn btn-sm btn-soft-primary"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <i className="ri-phone-line" />
                                </a>

                                <a
                                    href={`https://wa.me/${phone}`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="btn btn-sm btn-soft-success"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <i className="ri-whatsapp-line" />
                                </a>
                            </>
                        )}

                        <a
                            href={`mailto:${lead.email}`}
                            className="btn btn-sm btn-soft-secondary"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <i className="ri-mail-line" />
                        </a>
                    </div>
                );
            },
        }
    ],
        [handleStatusChange, loadingUpdate]
    );

    const renderLeadMobileCard = useCallback((lead: any) => {
        const phone = lead.phone?.replace(/\D/g, "");
        const car = lead.car;
        const initial = lead.name?.charAt(0)?.toUpperCase() ?? "?";
        const carImage = car?.images?.find((img: any) => img.is_primary)?.image;

        return (
            <div
                style={{
                    background: "#fff",
                    border: "1px solid #e9ebec",
                    borderRadius: 16,
                    overflow: "hidden",
                }}
            >
                <div className="d-flex align-items-start gap-3" style={{ padding: "14px 14px 12px" }}>
                    <div
                        className="flex-shrink-0 d-flex align-items-center justify-content-center fw-bold"
                        style={{
                            width: 42,
                            height: 42,
                            borderRadius: "50%",
                            background: "#405189",
                            color: "#fff",
                            fontSize: 16,
                        }}
                    >
                        {initial}
                    </div>
                    <div style={{ minWidth: 0, flex: 1 }}>
                        <h6 className="mb-0 fw-semibold text-body text-truncate">{lead.name}</h6>
                        {lead.phone && (
                            <span className="text-muted fs-13 d-block">{lead.phone}</span>
                        )}
                        <span className="text-muted fs-12 d-block text-truncate">{lead.email}</span>
                    </div>
                </div>

                <div style={{ borderTop: "1px solid #e9ebec", padding: "10px 14px" }}>
                    {car && (
                        <div className="d-flex align-items-center gap-2 mb-2">
                            {carImage && (
                                <img
                                    src={process.env.REACT_APP_PUBLIC_URL + carImage}
                                    alt=""
                                    className="rounded flex-shrink-0"
                                    style={{ width: 44, height: 30, objectFit: "cover" }}
                                />
                            )}
                            <span className="fw-semibold text-body fs-13 text-truncate">
                                {car.brand?.name} {car.model?.name}
                            </span>
                        </div>
                    )}
                    <LeadStatusBadge
                        currentStatus={lead.status}
                        onChange={(newStatus) => handleStatusChange(lead.id, newStatus)}
                        disabled={loadingUpdate}
                        size="md"
                    />
                </div>

                <div
                    className="d-flex align-items-center justify-content-between gap-2"
                    style={{ borderTop: "1px solid #e9ebec", padding: "10px 14px" }}
                >
                    <span className="text-muted fs-12 text-truncate">
                        {formatTimeDiff(lead.created_at)} · {lead.channel} - {lead.utm_source}
                    </span>
                    <div className="d-flex gap-2 flex-shrink-0">
                        {phone && (
                            <>
                                <a
                                    href={`tel:${phone}`}
                                    className="btn btn-sm btn-soft-primary"
                                    style={{ minWidth: 44, minHeight: 44, display: "inline-flex", alignItems: "center", justifyContent: "center" }}
                                >
                                    <i className="ri-phone-line" />
                                </a>
                                <a
                                    href={`https://wa.me/${phone}`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="btn btn-sm btn-soft-success"
                                    style={{ minWidth: 44, minHeight: 44, display: "inline-flex", alignItems: "center", justifyContent: "center" }}
                                >
                                    <i className="ri-whatsapp-line" />
                                </a>
                            </>
                        )}
                        <a
                            href={`mailto:${lead.email}`}
                            className="btn btn-sm btn-soft-secondary"
                            style={{ minWidth: 44, minHeight: 44, display: "inline-flex", alignItems: "center", justifyContent: "center" }}
                        >
                            <i className="ri-mail-line" />
                        </a>
                    </div>
                </div>
            </div>
        );
    }, [handleStatusChange, loadingUpdate]);

    document.title = "Leads | Xplendor";

    return (
        <div className="page-content">
            <Container fluid>
                <Row>
                    <Col lg={12}>
                        <div>
                            <Card>
                                <CardHeader className="border-0">
                                    <div className="d-flex align-items-center">
                                        <h5 className="card-title mb-0 flex-grow-1">Leads</h5>
                                    </div>
                                </CardHeader>
                                <div className="card-body pt-2">
                                    <XTanStackTable
                                        columns={columns}
                                        data={leads || []}
                                        loading={loading}
                                        pagination={pagination}
                                        onPaginationChange={setPagination}
                                        pageCount={meta?.last_page ?? 0}
                                        total={meta?.total}
                                        isBordered={true}
                                        theadClass="text-muted table-light"
                                        mobileMode={isMobile}
                                        renderMobileCard={renderLeadMobileCard}
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
