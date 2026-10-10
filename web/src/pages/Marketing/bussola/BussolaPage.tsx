import RestaurantBussola from "./RestaurantBussola";
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

export default function BussolaPage() {
    const { modules, isRoot } = useAccess();
    compassProviders(modules, isRoot); // o stand entra na parte 3

    return <RestaurantBussola />;
}
