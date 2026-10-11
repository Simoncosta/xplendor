import { Link } from "react-router-dom";
import { useAccess } from "contexts/ModulesContext";
import type { CompassPlay } from "common/models/bussola.model";
import type { StandCompassData, StandType } from "common/models/standCompass.model";
import BussolaPlays from "../BussolaPlays";
import { standAdapter } from "../compassAdapter";

/** O seletor de tipo (Todos, Carros, Autocaravanas), nos filtros da página e no cabeçalho do cartão do dashboard. */
export function TypeSelector({ data, onType }: { data: StandCompassData; onType: (t: StandType) => void }) {
    return (
        <div className="xp-seg flex-shrink-0" role="radiogroup" aria-label="Tipo de veículo" data-testid="stand-type">
            {data.types.map((t) => (
                <button key={t.key} type="button" role="radio" aria-checked={data.type === t.key} className={data.type === t.key ? "on" : ""} onClick={() => onType(t.key)}>{t.label}</button>
            ))}
        </div>
    );
}

/**
 * "As 3 jogadas da semana" do stand: o cartão comum da Bússola, com as chamadas do stand e os
 * botões de cada jogada. "Ver veículo" só a quem vê a área automóvel; "Rever preço" (a edição
 * do veículo) só a quem a pode editar.
 */
export default function StandPlays({ companyId, data, onChanged, headerActions }: {
    companyId: number; data: StandCompassData; onChanged?: () => void; headerActions?: React.ReactNode;
}) {
    const { can, has } = useAccess();
    const vehicles = has("stock");
    const playProps = (p: CompassPlay) => {
        const actions = p.actions ?? [];
        const extra = (
            <>
                {p.car_id && actions.includes("review_price") && vehicles && can("automovel.editar") && (
                    <Link className="btn btn-sm btn-outline-primary" to={`/cars/${p.car_id}`} data-testid="play-review-price"><i className="ri-price-tag-3-line me-1" />Rever preço</Link>
                )}
                {p.car_id && actions.includes("view_car") && vehicles && can("automovel.ver") && (
                    <Link className="btn btn-sm btn-outline-secondary" to={`/cars/${p.car_id}/ficha`} data-testid="play-view-car"><i className="ri-car-line me-1" />Ver veículo</Link>
                )}
            </>
        );
        return { showCreate: actions.includes("create"), showSuggest: actions.includes("suggest"), extraButtons: extra };
    };

    return <BussolaPlays companyId={companyId} data={data as any} onChanged={onChanged} adapter={standAdapter} headerActions={headerActions} playProps={playProps} />;
}
