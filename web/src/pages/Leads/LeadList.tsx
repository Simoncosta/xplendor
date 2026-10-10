// React
import { useCallback, useEffect, useState } from "react";
// Redux
import { useDispatch } from "react-redux";
// Components
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import ActionsMenu from "Components/Common/ActionsMenu";
import CarThumbnail from "Components/Common/CarThumbnail";
import LeadStatusBadge from "./components/LeadStatusBadge";
import LeadsFunnel from "./components/LeadsFunnel";
import LossReasonModal from "./components/LossReasonModal";
import { Container } from "reactstrap";
// Slices
import { updateLeadStatus } from "slices/leads/thunk";
import { getLeads } from "helpers/laravel_helper";
import { getWorkingCompanyId } from "helpers/workingCompany";

const formatTimeDiff = (dateStr: string): string => {
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 60) return `${diff}s`;
    if (diff < 3600) return `${Math.floor(diff / 60)}m`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
    return `${Math.floor(diff / 86400)}d`;
};


export default function LeadList() {
    const dispatch: any = useDispatch();

    // A lista pagina, ordena e pesquisa no servidor (CarLeadController::sorts): `leads` é só a página atual.
    const [leads, setLeads] = useState<any[]>([]);
    const [meta, setMeta] = useState<{ current_page: number; last_page: number; total: number; per_page: number; from: number | null; to: number | null } | null>(null);
    const [page, setPage] = useState(1);
    // Por omissão, como antes: as mais recentes primeiro.
    const [sort, setSort] = useState<{ key: "created_at" | "name" | "status" | "car"; dir: "asc" | "desc" }>({ key: "created_at", dir: "desc" });
    const [loading, setLoading] = useState(false);
    const [loadingUpdate, setLoadingUpdate] = useState(false);
    const [search, setSearch] = useState("");
    const [query, setQuery] = useState(""); // a pesquisa enviada (300 ms depois de parar de escrever)

    const [view, setView] = useState<"list" | "funnel">("list");
    // Perder na LISTA também exige motivo (mesmo modal do funil).
    const [pendingLost, setPendingLost] = useState<{ id: number; name: string } | null>(null);
    const [savingLost, setSavingLost] = useState(false);

    useEffect(() => {
        const t = setTimeout(() => { setQuery(search.trim()); setPage(1); }, 300);
        return () => clearTimeout(t);
    }, [search]);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        const companyId = getWorkingCompanyId();
        if (!authUser || !companyId) return;
        let alive = true;
        setLoading(true);
        getLeads({ page, perPage: 10, companyId, search: query || undefined, sort: sort.key, dir: sort.dir })
            .then((r: any) => {
                if (!alive) return;
                const { data, ...m } = r?.data ?? {};
                setLeads(data ?? []);
                setMeta(r?.data ? m : null);
            })
            .catch(() => { if (alive) { setLeads([]); setMeta(null); } })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [page, query, sort]);

    // "Tempo" é o tempo desde a entrada: do menor para o maior = as mais recentes primeiro
    // (created_at descendente). As outras colunas pedem o sentido tal como está.
    const onSortChange = useCallback((next: { id: string; key: string; desc: boolean } | null) => {
        if (!next) setSort({ key: "created_at", dir: "desc" });
        else if (next.key === "created_at") setSort({ key: "created_at", dir: next.desc ? "asc" : "desc" });
        else setSort({ key: next.key as "name" | "status" | "car", dir: next.desc ? "desc" : "asc" });
        setPage(1);
    }, []);

    // Muda o estado como antes (updateLeadStatus), com a mesma atualização otimista do reducer:
    // mostra logo o novo estado e volta ao anterior se a API falhar.
    const applyStatus = useCallback((leadId: number, status: string, lostReason?: string) => {
        const previous = leads.find((l) => l.id === leadId)?.status;
        setLeads((list) => list.map((l) => (l.id === leadId ? { ...l, status } : l)));
        setLoadingUpdate(true);
        return dispatch(updateLeadStatus(lostReason ? { leadId, status, lostReason } : { leadId, status }))
            .unwrap()
            .then((updated: any) => {
                if (updated?.id) setLeads((list) => list.map((l) => (l.id === updated.id ? { ...l, ...updated } : l)));
            })
            .catch(() => {
                setLeads((list) => list.map((l) => (l.id === leadId ? { ...l, status: previous } : l)));
            })
            .finally(() => setLoadingUpdate(false));
    }, [dispatch, leads]);

    const handleStatusChange = useCallback((leadId: number, status: string, leadName = "esta lead") => {
        // Não perder sem motivo: abre o modal quando o destino é "Perdida".
        if (status === "lost") {
            setPendingLost({ id: leadId, name: leadName });
            return;
        }
        applyStatus(leadId, status);
    }, [applyStatus]);

    const confirmLost = useCallback((reason: string) => {
        if (!pendingLost) return;
        setSavingLost(true);
        applyStatus(pendingLost.id, "lost", reason)
            .finally(() => { setSavingLost(false); setPendingLost(null); });
    }, [applyStatus, pendingLost]);

    const columnDefs: DTColumn<any>[] = [
        {
            id: "name",
            header: "Cliente",
            value: (lead) => `${lead.name ?? ""} ${lead.phone ?? ""} ${lead.email ?? ""}`,
            sortKey: "name",
            mobile: "title",
            cell: (lead) => {

                return (
                    <div>
                        <h6 className="mb-0">{lead.name}</h6>
                        <small className="text-muted d-block">{lead.phone || "Sem telefone"}</small>
                        <small className="text-muted">{lead.email}</small>
                    </div>
                );
            },
        },
        {
            id: "car",
            header: "Carro",
            sortKey: "car", // marca, modelo e versão
            value: (lead) => [lead.car?.brand?.name, lead.car?.model?.name, lead.car?.version].filter(Boolean).join(" "),
            cell: (lead) => {
                const car = lead.car;

                const image = car?.images?.find((img: any) => img.is_primary)?.image ?? null;

                return (
                    <div className="d-flex align-items-center gap-2">
                        <CarThumbnail src={image} variant="compact" width={50} height={35} />
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
            id: "status",
            header: "Estado",
            sortKey: "status",
            value: (lead) => lead.status,
            cell: (lead) => {
                return (
                    <LeadStatusBadge
                        currentStatus={lead.status}
                        onChange={(newStatus) => handleStatusChange(lead.id, newStatus, lead.name)}
                        disabled={loadingUpdate}
                        size="sm"
                    />
                );
            },
        },
        {
            // Tempo desde a entrada: ordenar do menor para o maior = as mais recentes primeiro.
            id: "created_at",
            header: "Tempo",
            sortKey: "created_at",
            value: (lead) => -new Date(lead.created_at).getTime(),
            cell: (lead) => formatTimeDiff(lead.created_at),
            nowrap: true,
        },
        {
            id: "channel",
            header: "Origem",
            value: (lead) => `${lead.channel ?? ""} - ${lead.utm_source ?? ""}`,
            cell: (lead) => {
                return (
                    <span className="text-muted">
                        {lead.channel} - {lead.utm_source}
                    </span>
                );
            },
        },
    ];
    const cols = useDataColumns("comercial.leads", columnDefs);

    const rowActions = (lead: any) => {
        const phone = lead.phone?.replace(/\D/g, "");
        return phone ? (
            <>
                <a href={`tel:${phone}`} className="btn btn-sm btn-outline-primary" title="Ligar" aria-label={`Ligar: ${lead.name}`} onClick={(e) => e.stopPropagation()}>
                    <i className="ri-phone-line" />
                </a>
                <a href={`https://wa.me/${phone}`} target="_blank" rel="noreferrer" className="btn btn-sm btn-outline-primary" title="WhatsApp" aria-label={`WhatsApp: ${lead.name}`} onClick={(e) => e.stopPropagation()}>
                    <i className="ri-whatsapp-line" />
                </a>
                <ActionsMenu size="sm" label={`Mais ações: ${lead.name}`} items={[
                    { label: "Enviar email", icon: "ri-mail-line", onClick: () => { window.location.href = `mailto:${lead.email}`; } },
                ]} />
            </>
        ) : (
            <a href={`mailto:${lead.email}`} className="btn btn-sm btn-outline-primary" title="Enviar email" aria-label={`Enviar email: ${lead.name}`} onClick={(e) => e.stopPropagation()}>
                <i className="ri-mail-line" />
            </a>
        );
    };

    const renderLeadMobileCard = useCallback((lead: any) => {
        const phone = lead.phone?.replace(/\D/g, "");
        const car = lead.car;
        const initial = lead.name?.charAt(0)?.toUpperCase() ?? "?";
        const carImage = car?.images?.find((img: any) => img.is_primary)?.image ?? null;

        return (
            <div
                style={{
                    background: "var(--vz-card-bg)",
                    border: "1px solid var(--vz-border-color)",
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
                            background: "var(--vz-primary)",
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

                <div style={{ borderTop: "1px solid var(--vz-border-color)", padding: "10px 14px" }}>
                    {car && (
                        <div className="d-flex align-items-center gap-2 mb-2">
                            <CarThumbnail src={carImage} variant="compact" width={44} height={30} />
                            <span className="fw-semibold text-body fs-13 text-truncate">
                                {car.brand?.name} {car.model?.name}
                            </span>
                        </div>
                    )}
                    <LeadStatusBadge
                        currentStatus={lead.status}
                        onChange={(newStatus) => handleStatusChange(lead.id, newStatus, lead.name)}
                        disabled={loadingUpdate}
                        size="md"
                    />
                </div>

                <div
                    className="d-flex align-items-center justify-content-between gap-2"
                    style={{ borderTop: "1px solid var(--vz-border-color)", padding: "10px 14px" }}
                >
                    <span className="text-muted fs-12 text-truncate">
                        {formatTimeDiff(lead.created_at)} · {lead.channel} - {lead.utm_source}
                    </span>
                    <div className="d-flex gap-2 flex-shrink-0 align-items-center">
                        {phone ? (
                            <>
                                <a
                                    href={`tel:${phone}`}
                                    className="btn btn-sm btn-outline-primary"
                                    aria-label={`Ligar: ${lead.name}`}
                                    style={{ minWidth: 44, minHeight: 44, display: "inline-flex", alignItems: "center", justifyContent: "center" }}
                                >
                                    <i className="ri-phone-line" />
                                </a>
                                <a
                                    href={`https://wa.me/${phone}`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="btn btn-sm btn-outline-primary"
                                    aria-label={`WhatsApp: ${lead.name}`}
                                    style={{ minWidth: 44, minHeight: 44, display: "inline-flex", alignItems: "center", justifyContent: "center" }}
                                >
                                    <i className="ri-whatsapp-line" />
                                </a>
                                <ActionsMenu label={`Mais ações: ${lead.name}`} items={[
                                    { label: "Enviar email", icon: "ri-mail-line", onClick: () => { window.location.href = `mailto:${lead.email}`; } },
                                ]} />
                            </>
                        ) : (
                            <a
                                href={`mailto:${lead.email}`}
                                className="btn btn-sm btn-outline-primary"
                                aria-label={`Enviar email: ${lead.name}`}
                                style={{ minWidth: 44, minHeight: 44, display: "inline-flex", alignItems: "center", justifyContent: "center" }}
                            >
                                <i className="ri-mail-line" />
                            </a>
                        )}
                    </div>
                </div>
            </div>
        );
    }, [handleStatusChange, loadingUpdate]);

    const viewToggle = (
        <div className="xp-seg" role="tablist" aria-label="Vista">
            <button type="button" role="tab" aria-selected={view === "list"} className={view === "list" ? "on" : ""} onClick={() => setView("list")}>
                <i className="ri-list-check me-1" />Lista
            </button>
            <button type="button" role="tab" aria-selected={view === "funnel"} className={view === "funnel" ? "on" : ""} onClick={() => setView("funnel")}>
                <i className="ri-layout-grid-line me-1" />Funil
            </button>
        </div>
    );

    document.title = "Leads | Xplendor";

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader
                    title="Leads"
                    breadcrumbs={[{ label: "Comercial" }]}
                    info="Os contactos interessados nas suas viaturas. Mude o estado de cada lead na lista ou arraste-a no funil."
                />
                <PageCard
                    title={view === "funnel" ? "Funil" : "Lista"}
                    status={view === "list" && meta ? <>{meta.total} lead{meta.total === 1 ? "" : "s"}</> : undefined}
                    loading={view === "list" && loading && leads.length > 0}
                    flush={view === "list"}
                    actions={<>{viewToggle}{view === "list" && cols.selector}</>}
                    filters={view === "list" ? (
                        <RestFilterBar search={search} onSearchChange={setSearch} searchPlaceholder="Pesquisar (nome, telefone, email ou carro)…"
                            activeCount={search ? 1 : 0} onClear={() => setSearch("")} />
                    ) : undefined}
                >
                    {view === "funnel" ? (
                        <LeadsFunnel />
                    ) : (
                        <DataTable
                            columns={cols}
                            data={leads}
                            rowKey={(lead: any) => lead.id}
                            mode="server"
                            loading={loading}
                            caption="Leads"
                            empty={{ message: query ? "Nenhuma lead para esta pesquisa." : "Ainda não há leads." }}
                            server={meta ? {
                                page: meta.current_page, lastPage: meta.last_page, total: meta.total, perPage: meta.per_page,
                                from: meta.from ?? 0, to: meta.to ?? 0, onPageChange: setPage,
                                sort: sort.key === "created_at" ? { id: "created_at", desc: sort.dir === "asc" } : { id: sort.key, desc: sort.dir === "desc" },
                                onSortChange,
                            } : undefined}
                            rowActions={rowActions}
                            mobileCard={(lead) => renderLeadMobileCard(lead)}
                        />
                    )}
                </PageCard>
            </Container>

            <LossReasonModal
                isOpen={!!pendingLost}
                leadName={pendingLost?.name}
                saving={savingLost}
                onConfirm={confirmLost}
                onCancel={() => setPendingLost(null)}
            />
        </div>
    );
}
