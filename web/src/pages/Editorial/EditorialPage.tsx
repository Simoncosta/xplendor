import { useCallback, useEffect, useMemo, useState } from "react";
import { Container, Spinner } from "reactstrap";
import { useSearchParams } from "react-router-dom";
import { ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import { useWorkingCompany } from "contexts/WorkingCompanyContext";
import { getAgencyCompanies } from "helpers/laravel_helper";
import EditorialCalendarPage from "./EditorialCalendarPage";
import AgencyEditorialAll, { AgencyClient } from "./AgencyEditorialAll";
import XSelect from "./XSelect";

/**
 * A rota da Linha Editorial. Numa empresa (ou a trabalhar num cliente) é a Linha Editorial
 * dessa empresa. No contexto da agência há um filtro por cliente (?cliente=todos|ID):
 * "Todos os clientes" mostra a vista de todas as empresas com a Linha Editorial (a agência e
 * os clientes que a pessoa vê); um cliente escolhido mostra a Linha Editorial completa dele.
 */
const ALL = "todos";

export default function EditorialPage() {
    const wc = useWorkingCompany();
    const agencyId = wc?.agencyMode ? wc.workingId : 0;
    const [clients, setClients] = useState<AgencyClient[] | null>(null);
    const [searchParams, setSearchParams] = useSearchParams();

    useEffect(() => {
        if (!agencyId) return;
        getAgencyCompanies(agencyId)
            .then((r: any) => setClients(((r?.data ?? []) as AgencyClient[]).filter((c) => c.has_editorial)))
            .catch(() => setClients([]));
    }, [agencyId]);

    const raw = searchParams.get("cliente") ?? ALL;
    const chosen = clients?.find((c) => String(c.id) === raw)?.id ?? 0;

    const choose = useCallback((v: string) => {
        setSearchParams((prev) => {
            const next = new URLSearchParams(prev);
            next.set("cliente", v);
            // O Feed só existe com um cliente escolhido.
            if (v === ALL && next.get("vista") === "feed") next.set("vista", "calendario");
            return next;
        }, { replace: true });
    }, [setSearchParams]);

    const onView = useCallback((vista: string, mes: string) => {
        setSearchParams((prev) => {
            const next = new URLSearchParams(prev);
            next.set("vista", vista);
            next.set("mes", mes);
            return next;
        }, { replace: true });
    }, [setSearchParams]);

    const options = useMemo(() => [
        { value: ALL, label: "Todos os clientes" },
        ...(clients ?? []).map((c) => ({ value: String(c.id), label: c.is_agency ? `${c.name} (a agência)` : c.name })),
    ], [clients]);

    if (!agencyId) return <EditorialCalendarPage />;

    if (clients === null) {
        return <div className="page-content"><Container fluid><div className="text-center py-5"><Spinner color="primary" /></div></Container></div>;
    }

    const filter = (
        <div style={{ minWidth: 200, maxWidth: "100%" }} data-testid="agency-client-filter">
            <XSelect small searchable ariaLabel="Cliente" options={options} value={chosen ? String(chosen) : ALL} onChange={choose} />
        </div>
    );

    if (chosen) return <EditorialCalendarPage key={chosen} companyIdOverride={chosen} clientFilter={filter} />;

    document.title = "Linha Editorial | Xplendor";
    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Linha Editorial" breadcrumbs={[{ label: "Marketing" }]} filters={filter} />
                <AgencyEditorialAll agencyId={agencyId} clients={clients}
                    initialView={searchParams.get("vista")} initialMonth={searchParams.get("mes")} onView={onView} />
            </Container>
        </div>
    );
}
