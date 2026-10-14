import { Link } from "react-router-dom";
import { useAccess } from "contexts/ModulesContext";
import type { CompassPlay } from "common/models/bussola.model";
import type { ImoveisCompassData, ImoveisType } from "common/models/imoveisCompass.model";
import BussolaPlays from "../BussolaPlays";
import { imoveisAdapter } from "../compassAdapter";

/** O seletor (Todos, Venda, Arrendamento). */
export function ImoveisTypeSelector({ data, onType }: { data: ImoveisCompassData; onType: (t: ImoveisType) => void }) {
    return (
        <div className="xp-seg flex-shrink-0" role="radiogroup" aria-label="Venda ou arrendamento" data-testid="imoveis-type">
            {data.types.map((t) => (
                <button key={t.key} type="button" role="radio" aria-checked={data.type === t.key} className={data.type === t.key ? "on" : ""} onClick={() => onType(t.key)}>{t.label}</button>
            ))}
        </div>
    );
}

/**
 * As 3 jogadas da semana do imobiliário: o cartão comum da Bússola, com as chamadas do
 * imobiliário. "Ver imóvel" só a quem vê os imóveis; na jogada do BCE, a ligação ao comunicado
 * oficial (abre noutro separador).
 */
export default function ImoveisPlays({ companyId, data, onChanged }: { companyId: number; data: ImoveisCompassData; onChanged?: () => void }) {
    const { can } = useAccess();
    const playProps = (p: CompassPlay) => {
        const actions = p.actions ?? [];
        const extra = (
            <>
                {p.link && <a className="btn btn-sm btn-outline-secondary" href={p.link} target="_blank" rel="noopener noreferrer" data-testid="play-official-link"><i className="ri-external-link-line me-1" />Ler o comunicado</a>}
                {p.property_id && actions.includes("view_property") && can("imoveis.ver") && (
                    <Link className="btn btn-sm btn-outline-secondary" to={`/imoveis?id=${p.property_id}`} data-testid="play-view-property"><i className="ri-home-4-line me-1" />Ver imóvel</Link>
                )}
            </>
        );
        return { showCreate: actions.includes("create"), showSuggest: actions.includes("suggest"), extraButtons: extra };
    };

    return <BussolaPlays companyId={companyId} data={data as any} onChanged={onChanged} adapter={imoveisAdapter} playProps={playProps} />;
}
