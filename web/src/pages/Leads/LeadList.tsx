// React
import { useEffect, useMemo, useState } from "react";
// Redux
import { useDispatch, useSelector } from "react-redux";
// Components
import XTanStackTable from "Components/Common/XTanStackTable";
import {
    Container,
    Row,
    Card,
    CardHeader,
    Col,
    Badge,
} from "reactstrap";
// Slices
import { getLeadsPaginate } from "slices/thunks";

export default function LeadList() {
    const dispatch: any = useDispatch();

    const { leads, meta, loading } = useSelector(
        (state: any) => state.Lead
    );

    const [companyId, setCompanyId] = useState<any>(null);
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
                getLeadsPaginate({
                    page: pagination.pageIndex + 1,
                    perPage: pagination.pageSize,
                    companyId: obj.company_id,
                })
            );
        }
    }, [dispatch, pagination.pageIndex, pagination.pageSize]);

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
                                src={image}
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
                const status = row.original.status;

                const map: any = {
                    new: "secondary",
                    contacted: "info",
                    qualified: "primary",
                    won: "success",
                    lost: "danger",
                    spam: "dark",
                };

                return <Badge color={map[status] || "secondary"}>{status}</Badge>;
            },
        },
        {
            enableColumnFilter: false,
            header: "Tempo",
            accessorKey: "created_at",
            cell: ({ row }: any) => {
                const date = new Date(row.original.created_at);
                const now = new Date();

                const diff = Math.floor((now.getTime() - date.getTime()) / 1000);

                if (diff < 60) return `${diff}s`;
                if (diff < 3600) return `${Math.floor(diff / 60)}m`;
                if (diff < 86400) return `${Math.floor(diff / 3600)}h`;

                return `${Math.floor(diff / 86400)}d`;
            },
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
        []
    );

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