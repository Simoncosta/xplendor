import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Spinner } from "reactstrap";
import { getBussola } from "helpers/laravel_helper";
import type { CompassData } from "common/models/bussola.model";
import BussolaSummary from "./BussolaSummary";

/**
 * Dashboard do restaurante, separador "Marketing e resultados": abre com o resumo da Bússola
 * (o topo e as 3 jogadas, o MESMO componente da página) e "Abrir a Bússola completa".
 */
export default function BussolaDashboardSummary({ companyId }: { companyId: number }) {
    const [locationId, setLocationId] = useState(0);
    const [data, setData] = useState<CompassData | null>(null);
    const [loading, setLoading] = useState(true);

    const load = useCallback(() => {
        setLoading(true);
        getBussola(companyId, locationId || null, true).then((r: any) => setData(r?.data ?? null)).catch(() => setData(null)).finally(() => setLoading(false));
    }, [companyId, locationId]);
    useEffect(() => { load(); }, [load]);

    if (!data) return loading ? <div className="text-center py-4"><Spinner color="primary" size="sm" /></div> : null;
    if (!data.enabled || !data.top) return null;

    return (
        <div className={`mb-4${loading ? " opacity-50" : ""}`} data-testid="dashboard-bussola">
            <BussolaSummary companyId={companyId} data={data} onLocation={setLocationId} onChanged={load} />
            <div className="text-end">
                <Link to="/marketing/bussola" className="btn btn-outline-primary btn-sm"><i className="ri-compass-3-line me-1" aria-hidden />Abrir a Bússola completa</Link>
            </div>
        </div>
    );
}
