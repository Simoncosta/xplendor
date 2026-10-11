import { useState } from "react";
import RestaurantBussola from "./RestaurantBussola";
import StandBussola from "./stand/StandBussola";
import { useAccess } from "contexts/ModulesContext";

/**
 * Bússola comum: a página escolhe o fornecedor pelos módulos da empresa. Com o PingWin, a
 * restauração (as vendas e as reservas); com o stock, o stand (parte 3). Uma empresa com os
 * dois mostra a da restauração e um seletor para a do stand.
 */
export type CompassProviderKey = "restauracao" | "stand";

/** Os fornecedores que a empresa tem, pela ordem de preferência (o root na própria empresa vê a restauração). */
export const compassProviders = (modules: string[] | null, isRoot: boolean): CompassProviderKey[] => {
    const out: CompassProviderKey[] = [];
    if (modules?.includes("pingwin") || (isRoot && !modules?.includes("stock"))) out.push("restauracao");
    if (modules?.includes("stock")) out.push("stand");
    return out.length ? out : ["restauracao"];
};

const LABELS: Record<CompassProviderKey, string> = { restauracao: "Restauração", stand: "Stand" };

export default function BussolaPage() {
    const { modules, isRoot } = useAccess();
    const providers = compassProviders(modules, isRoot);
    const [chosen, setChosen] = useState<CompassProviderKey | null>(null);
    const current = chosen && providers.includes(chosen) ? chosen : providers[0];
    // Com os dois ramos, um seletor nos filtros da página.
    const providerSwitch = providers.length > 1 ? (
        <div className="xp-seg flex-shrink-0" role="radiogroup" aria-label="Ramo" data-testid="compass-provider">
            {providers.map((p) => <button key={p} type="button" role="radio" aria-checked={current === p} className={current === p ? "on" : ""} onClick={() => setChosen(p)}>{LABELS[p]}</button>)}
        </div>
    ) : undefined;

    return current === "stand" ? <StandBussola providerSwitch={providerSwitch} /> : <RestaurantBussola providerSwitch={providerSwitch} />;
}
