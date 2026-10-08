import { Link } from "react-router-dom";
import ReasonButton from "Components/Common/ReasonButton";
import { RestaurantSignalItem } from "common/models/pingwin.model";

/**
 * XPLENDOR — F3: uma sugestão de "O que publicar e quando": título, frase, números em
 * destaque, amostra, confiança e, quando se pode agir, "Criar publicação" e "Ignorar".
 */

const WEEKDAY_LONG = ["domingo", "segunda-feira", "terça-feira", "quarta-feira", "quinta-feira", "sexta-feira", "sábado"];
export const fmtDay = (d?: string | null) => (d ? new Date(`${d.slice(0, 10)}T00:00:00`).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit" }) : "");
export const fmtWeekday = (d?: string | null) => (d ? WEEKDAY_LONG[new Date(`${d.slice(0, 10)}T00:00:00`).getDay()] : "");
const eur = (cents: number) => (cents / 100).toLocaleString("pt-PT", { maximumFractionDigits: 0 }) + " €";
const int = (n: number) => n.toLocaleString("pt-PT");

export function ConfidenceBadge({ value }: { value: "alta" | "media" }) {
    return (
        <span className={`badge ${value === "alta" ? "bg-success-subtle text-success" : "bg-info-subtle text-info"}`}>
            Confiança {value === "alta" ? "alta" : "média"}
        </span>
    );
}

/** Os números em destaque de cada tipo de sugestão. */
function highlights(s: RestaurantSignalItem): string[] {
    const n = s.numbers || {};
    switch (s.type) {
        case "weak_period":
            return [`${n.pct_below}% abaixo da média`, `média de ${eur(n.avg_cents)}`, `${n.occurrences} ocorrências`];
        case "item_up":
        case "item_down":
            return [`${int(n.qty_now)} contra ${int(n.qty_before)} unidades`, `${n.variation_pct > 0 ? "mais" : "menos"} ${Math.abs(Math.round(n.variation_pct))}%`];
        case "stale_item":
            return [`${n.days_without_sales} dias sem vendas`, `${int(n.qty_before)} unidades nas 8 semanas anteriores`];
        default:
            return [];
    }
}

/** A amostra, em texto curto. */
export function sampleText(s: RestaurantSignalItem): string {
    const sm = s.sample || {};
    if (sm.from && sm.to) {
        const extra = s.type === "weak_period" && sm.special_days_excluded?.length
            ? `; ${sm.special_days_excluded.length} ${sm.special_days_excluded.length === 1 ? "dia especial fora da média" : "dias especiais fora da média"}`
            : "";
        return `De ${fmtDay(sm.from)} a ${fmtDay(sm.to)}${extra}`;
    }
    if (sm.reservations) return `${int(sm.reservations)} reservas em ${sm.days} dias`;
    return "";
}

type Props = {
    signal: RestaurantSignalItem;
    canAct: boolean;
    canActReason: string | null;
    busy?: boolean;
    showLocation?: boolean;
    onCreate: (s: RestaurantSignalItem) => void;
    onIgnore: (s: RestaurantSignalItem) => void;
    onRestore: (s: RestaurantSignalItem) => void;
};

export default function SignalCard({ signal: s, canAct, canActReason, busy, onCreate, onIgnore, onRestore }: Props) {
    const chips = highlights(s);
    return (
        <div className="border rounded p-3 h-100 d-flex flex-column" style={{ borderColor: "var(--vz-border-color)", background: "var(--vz-secondary-bg)" }}>
            <div className="d-flex justify-content-between align-items-start gap-2 mb-2">
                <h6 className="fw-semibold mb-0 text-body" style={{ wordBreak: "break-word" }}>{s.title}</h6>
                <ConfidenceBadge value={s.confidence} />
            </div>
            <p className="fs-13 mb-2 text-body">{s.sentence}</p>
            {chips.length > 0 && (
                <div className="d-flex flex-wrap gap-1 mb-2">
                    {chips.map((c) => <span key={c} className="badge bg-primary-subtle text-primary fw-medium">{c}</span>)}
                </div>
            )}
            <div className="text-muted fs-12 mb-3">
                {sampleText(s)}
                {s.suggested_date && <> · Publicar: {fmtWeekday(s.suggested_date)}, {fmtDay(s.suggested_date)}</>}
            </div>
            <div className="mt-auto">
                {s.post ? (
                    <div className="fs-13">
                        <span className="badge bg-success-subtle text-success me-1">Publicação criada</span>
                        <Link to="/editorial">{s.post.title}</Link>
                        <span className="text-muted"> ({fmtDay(s.post.publish_date)})</span>
                    </div>
                ) : s.hidden ? (
                    <div className="d-flex flex-wrap align-items-center gap-2">
                        <span className="text-muted fs-12">Escondida até {fmtDay(s.hidden_until)}.</span>
                        <ReasonButton size="sm" color="outline-primary" onClick={() => onRestore(s)} disabled={busy} reason={canAct ? null : canActReason}>Voltar a mostrar</ReasonButton>
                    </div>
                ) : (
                    <div className="d-flex flex-wrap gap-2">
                        <ReasonButton size="sm" color="outline-primary" onClick={() => onCreate(s)} disabled={busy} reason={canAct ? null : canActReason}>
                            <i className="ri-add-line me-1" />Criar publicação
                        </ReasonButton>
                        <ReasonButton size="sm" color="light" onClick={() => onIgnore(s)} disabled={busy} reason={canAct ? null : canActReason}>
                            Ignorar
                        </ReasonButton>
                    </div>
                )}
            </div>
        </div>
    );
}
