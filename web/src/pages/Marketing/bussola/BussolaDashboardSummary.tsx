import { useCallback, useEffect, useState } from "react";
import { getBussola } from "helpers/laravel_helper";
import type { CompassData } from "common/models/bussola.model";
import BussolaPlays from "./BussolaPlays";
import { FinancialNote } from "Components/Common/HiddenMoney";

/**
 * Dashboard do restaurante, fim do separador "Marketing e resultados" (junto às
 * Recomendações): "As 3 jogadas da semana", o MESMO cartão da página da Bússola, com o seletor
 * de loja no cabeçalho. O topo da semana (vendas, família, reservas) já está no separador Vendas.
 */
export default function BussolaDashboardSummary({ companyId }: { companyId: number }) {
    const [locationId, setLocationId] = useState(0);
    const [data, setData] = useState<CompassData | null>(null);
    const [loading, setLoading] = useState(true);

    const load = useCallback(() => {
        setLoading(true);
        getBussola(companyId, locationId || null, false, true).then((r: any) => setData(r?.data ?? null)).catch(() => setData(null)).finally(() => setLoading(false));
    }, [companyId, locationId]);
    useEffect(() => { load(); }, [load]);

    if (!data || !data.enabled) return null;

    return (
        <div className={loading ? "opacity-50" : undefined} data-testid="dashboard-bussola">
            <BussolaPlays companyId={companyId} data={data} onLocation={setLocationId} onChanged={load} />
            {data.financial?.visible === false && <FinancialNote className="mt-2" />}
        </div>
    );
}
