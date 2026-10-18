import { useCallback, useEffect, useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { Badge, Button, Container } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import { useCan, useModules } from "contexts/ModulesContext";
import { confirmAction } from "helpers/swal";
import { getWorkingCompanyId } from "helpers/workingCompany";
import { deleteProperty, getListingsSummary, getProperties } from "helpers/laravel_helper";
import type { Property, PropertyOptions } from "common/models/imoveisCompass.model";
import type { ListingsSummary } from "common/models/consultantSite.model";
import PropertyModal, { type Tab } from "./PropertyModal";

/**
 * Imóveis (Bússola Master, R3): a carteira do consultor imobiliário, só com os campos que a
 * Bússola usa. Sem morada exata nem dados de proprietários, compradores ou inquilinos. A classe
 * energética é obrigatória em qualquer anúncio; o interesse são contagens manuais com a data.
 */

const eur = (v: number, listing: string) => `${v.toLocaleString("pt-PT")} €${listing === "arrendamento" ? " por mês" : ""}`;
const day = (d: string | null) => (d ? new Date(`${d}T12:00:00`).toLocaleDateString("pt-PT") : "");
const errorText = (e: any, fallback: string) => (e?.errors ? (Object.values(e.errors).flat()[0] as string) : null) || e?.message || fallback;
const area = (v: number | null) => (v ? `${String(v).replace(".", ",")} m²` : "");
const STATUS_COLOR: Record<string, string> = { disponivel: "success", reservado: "warning", fechado: "secondary" };

export default function ImoveisPage() {
    document.title = "Imóveis | Xplendor";
    const companyId = getWorkingCompanyId();
    const [params] = useSearchParams();
    const [rows, setRows] = useState<Property[]>([]);
    const [options, setOptions] = useState<PropertyOptions | null>(null);
    const [loading, setLoading] = useState(true);
    const [status, setStatus] = useState<string>("");
    const [editing, setEditing] = useState<Property | "new" | null>(null);
    const [editTab, setEditTab] = useState<Tab>("data");
    const open = (p: Property | "new", tab: Tab = "data") => { setEditTab(tab); setEditing(p); };
    const [canCreate, createReason] = useCan("imoveis.criar");
    const [canEdit] = useCan("imoveis.editar");
    const [canDelete] = useCan("imoveis.apagar");
    const [, editReason] = useCan("imoveis.editar");
    // Site do consultor: o estado no site, a capa e as visualizações dos últimos 30 dias.
    const siteOn = useModules().has("site_consultor");
    const [summary, setSummary] = useState<ListingsSummary | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const r: any = await getProperties(companyId, status ? { status } : {});
            setRows(r?.data?.properties ?? []);
            setOptions(r?.data?.options ?? null);
            if (siteOn) getListingsSummary(companyId).then((x: any) => setSummary(x?.data ?? null)).catch(() => setSummary(null));
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível carregar os imóveis."));
        } finally {
            setLoading(false);
        }
    }, [companyId, status, siteOn]);
    useEffect(() => { void load(); }, [load]);
    // "Ver imóvel" a partir da Bússola: abre a ficha (o formulário) desse imóvel.
    useEffect(() => {
        const id = Number(params.get("id"));
        if (id && rows.length) { const p = rows.find((r) => r.id === id); if (p) open(p); }
    }, [params, rows]);

    const remove = async (p: Property) => {
        if (!(await confirmAction({ title: `Apagar o imóvel ${p.reference}?`, text: "Sai da carteira e da Bússola.", confirmText: "Apagar", icon: "warning", confirmVariant: "danger" }))) return;
        try {
            await deleteProperty(companyId, p.id);
            toast.success("Imóvel apagado.");
            void load();
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível apagar o imóvel."));
        }
    };

    const cols = useDataColumns<Property>("imoveis.carteira", [
        { id: "reference", header: "Referência", value: (p) => p.reference, hideable: false, mobile: "subtitle", nowrap: true },
        { id: "title", header: "Imóvel", value: (p) => p.title, mobile: "title", cell: (p) => <span className="text-wrap d-inline-block" style={{ maxWidth: 280 }}>{p.title}<div className="text-muted fs-12">{p.kind_label}{p.typology ? ` ${p.typology}` : ""}{p.area_m2 ? ` · ${area(p.area_m2)}` : ""}</div></span> },
        { id: "parish", header: "Freguesia", value: (p) => p.parish ?? "", cell: (p) => p.parish ? <>{p.parish}<div className="text-muted fs-12">{p.municipality}</div></> : <span className="text-muted">Por indicar</span> },
        { id: "price", header: "Preço", align: "end", value: (p) => p.price, cell: (p) => <>{eur(p.price, p.listing)}{p.ine && p.listing === "venda" ? <div className="text-muted fs-12">INE: {p.ine.median_m2.toLocaleString("pt-PT")} €/m² ({p.ine.period})</div> : null}</>, nowrap: true },
        { id: "energy", header: "Classe energética", value: (p) => p.energy_class ?? "", cell: (p) => p.energy_class_missing
            ? <Badge color="warning-subtle" className="text-warning fw-normal">{p.energy_class === "em_processo" ? "Em processo" : "Em falta"}</Badge> : (p.energy_class === "isento" ? "Isento" : p.energy_class) },
        { id: "status", header: "Estado", value: (p) => p.status, cell: (p) => <Badge color={`${STATUS_COLOR[p.status]}-subtle`} className={`text-${STATUS_COLOR[p.status]} fw-normal`}>{options?.statuses[p.status] ?? p.status}</Badge> },
        { id: "days", header: "Dias em carteira", align: "end", value: (p) => p.days ?? 0 },
        { id: "interest", header: "Interesse", value: (p) => p.contacts_30, cell: (p) => <>{p.contacts_30} contactos · {p.visits_30} visitas
            {siteOn
                ? <div className="text-muted fs-12">site: {p.contacts_30_site ?? 0} contactos, {p.visits_30_site ?? 0} pedidos de visita · outros canais: {p.contacts_30_manual ?? 0} contactos, {p.visits_30_manual ?? 0} visitas</div>
                : p.interest_updated_at ? <div className="text-muted fs-12">à mão, a {day(p.interest_updated_at)}</div> : null}</> },
        ...(siteOn ? [{ id: "site", header: "Site", value: (p: Property) => summary?.properties[p.id]?.views_30 ?? 0, cell: (p: Property) => {
            const s = summary?.properties[p.id];
            const on = s?.site_status === "publicado";
            return <><button type="button" className="btn btn-link p-0 border-0" onClick={() => open(p, "site")} aria-label={`Site do imóvel ${p.reference}`} data-testid={`property-site-${p.id}`}>
                    <Badge color={on ? "success-subtle" : "secondary-subtle"} className={`${on ? "text-success" : "text-secondary"} fw-normal`}>{on ? "Publicado" : "Rascunho"}</Badge></button>
                <div className="text-muted fs-12">{s?.views_30 ?? 0} visualizações em 30 dias</div></>;
        } }] as DTColumn<Property>[] : []),
        { id: "actions", header: "", hideable: false, cell: (p) => (
            <div className="d-flex gap-1 justify-content-end">
                <Button size="sm" color="secondary" outline onClick={() => open(p)} aria-label={`${canEdit ? "Editar" : "Abrir"} ${p.reference}`}><i className={canEdit ? "ri-pencil-line" : "ri-eye-line"} /></Button>
                {canDelete && <Button size="sm" color="danger" outline onClick={() => void remove(p)} aria-label={`Apagar ${p.reference}`}><i className="ri-delete-bin-line" /></Button>}
            </div>
        ) },
    ] as DTColumn<Property>[]);
    const statusOptions = useMemo(() => [{ value: "", label: "Todos os estados" }, ...Object.entries(options?.statuses ?? {}).map(([k, l]) => ({ value: k, label: l }))], [options]);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Imóveis" breadcrumbs={[{ label: "Imobiliário" }]}
                    info="A carteira de imóveis que a Bússola do imobiliário usa: os dias em carteira, a referência oficial do INE da freguesia e as contagens manuais de contactos e visitas. Sem morada nem dados de pessoas." />
                <PageCard title="Carteira" data-testid="imoveis-page"
                    actions={<>
                        <div style={{ minWidth: 180 }}><XSelect<string> small ariaLabel="Estado" options={statusOptions} value={status} onChange={setStatus} /></div>
                        <ReasonButton size="sm" color="primary" reason={canCreate ? null : createReason} onClick={() => open("new")} data-testid="property-new"><i className="ri-add-line me-1" />Novo imóvel</ReasonButton>
                    </>}>
                    <DataTable columns={cols} data={rows} rowKey={(p) => p.id} loading={loading} caption="Imóveis" empty={{ message: "Ainda não há imóveis na carteira." }} />
                </PageCard>
            </Container>
            <PropertyModal companyId={companyId} editing={editing} initialTab={editTab} options={options} siteOn={siteOn} canEdit={canEdit} editReason={editReason}
                onClose={() => { setEditing(null); void load(); }} onChanged={() => { if (siteOn) getListingsSummary(companyId).then((x: any) => setSummary(x?.data ?? null)).catch(() => undefined); }} />
        </div>
    );
}
