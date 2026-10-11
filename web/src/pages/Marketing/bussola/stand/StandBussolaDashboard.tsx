import { useCallback, useEffect, useState } from "react";
import { getBussolaStand } from "helpers/laravel_helper";
import { FinancialNote } from "Components/Common/HiddenMoney";
import type { StandCompassData, StandType } from "common/models/standCompass.model";
import StandPlays, { TypeSelector } from "./StandPlays";

/**
 * Dashboard do stand, fim do separador "Marketing e resultados": "As 3 jogadas da semana", o
 * MESMO cartão da página da Bússola, com o seletor de tipo (Todos, Carros, Autocaravanas) no
 * cabeçalho. Sem o topo: os números do stock já estão no separador Stock.
 */
export default function StandBussolaDashboard({ companyId }: { companyId: number }) {
    const [type, setType] = useState<StandType>("all");
    const [data, setData] = useState<StandCompassData | null>(null);
    const [loading, setLoading] = useState(true);

    const load = useCallback(() => {
        setLoading(true);
        getBussolaStand(companyId, type, true).then((r: any) => setData(r?.data ?? null)).catch(() => setData(null)).finally(() => setLoading(false));
    }, [companyId, type]);
    useEffect(() => { load(); }, [load]);

    if (!data || !data.enabled) return null;

    return (
        <div className={loading ? "opacity-50" : undefined} data-testid="dashboard-bussola-stand">
            <StandPlays companyId={companyId} data={data} onChanged={load} headerActions={<TypeSelector data={data} onType={setType} />} />
            {data.financial?.visible === false && <FinancialNote className="mt-2" />}
        </div>
    );
}
