import { useState } from "react";
import { Container } from "reactstrap";
import { ToastContainer } from "react-toastify";
import RestaurantBussola from "./RestaurantBussola";
import StandBussola from "./stand/StandBussola";
import ImoveisBussola from "./imoveis/ImoveisBussola";
import RadarCard from "./radar/RadarCard";
import PageHeader from "Components/Common/PageHeader";
import { useAccess } from "contexts/ModulesContext";
import { getWorkingCompanyId } from "helpers/workingCompany";

/**
 * Bússola comum: a página escolhe o fornecedor pelos módulos da empresa. Com o PingWin, a
 * restauração (as vendas e as reservas); com o stock, o stand (parte 3). Uma empresa com os
 * dois mostra a da restauração e um seletor para a do stand. Com o módulo radar, o bloco
 * "Radar" aparece logo a seguir às jogadas (ou sozinho, numa empresa sem fornecedor).
 */
export type CompassProviderKey = "restauracao" | "stand" | "imobiliario";

/** Os fornecedores que a empresa tem, pela ordem de preferência (o root na própria empresa vê a restauração). */
export const compassProviders = (modules: string[] | null, isRoot: boolean): CompassProviderKey[] => {
    const out: CompassProviderKey[] = [];
    if (modules?.includes("pingwin") || (isRoot && !modules?.includes("stock") && !modules?.includes("imoveis"))) out.push("restauracao");
    if (modules?.includes("stock")) out.push("stand");
    if (modules?.includes("imoveis")) out.push("imobiliario");
    // Só o Radar (sem PingWin nem stock): nenhum fornecedor, a página mostra só o Radar.
    if (!out.length && modules?.includes("radar")) return [];
    return out.length ? out : ["restauracao"];
};

const LABELS: Record<CompassProviderKey, string> = { restauracao: "Restauração", stand: "Stand", imobiliario: "Imobiliário" };

export default function BussolaPage() {
    const { modules, isRoot } = useAccess();
    const providers = compassProviders(modules, isRoot);
    const [chosen, setChosen] = useState<CompassProviderKey | null>(null);
    const companyId = getWorkingCompanyId();
    const radar = modules?.includes("radar") && companyId ? <RadarCard companyId={companyId} /> : undefined;
    if (providers.length === 0) {
        return (
            <div className="page-content">
                <ToastContainer />
                <Container fluid>
                    <PageHeader title="Bússola" breadcrumbs={[{ label: "Marketing" }]}
                        info="O que está a subir agora e as datas com fonte oficial que interessam ao ramo da sua marca. O Radar nunca publica nem escreve o texto." />
                    {radar}
                </Container>
            </div>
        );
    }
    const current = chosen && providers.includes(chosen) ? chosen : providers[0];
    // Com os dois ramos, um seletor nos filtros da página.
    const providerSwitch = providers.length > 1 ? (
        <div className="xp-seg flex-shrink-0" role="radiogroup" aria-label="Ramo" data-testid="compass-provider">
            {providers.map((p) => <button key={p} type="button" role="radio" aria-checked={current === p} className={current === p ? "on" : ""} onClick={() => setChosen(p)}>{LABELS[p]}</button>)}
        </div>
    ) : undefined;

    if (current === "imobiliario") return <ImoveisBussola providerSwitch={providerSwitch} radar={radar} />;
    return current === "stand" ? <StandBussola providerSwitch={providerSwitch} radar={radar} /> : <RestaurantBussola providerSwitch={providerSwitch} radar={radar} />;
}
