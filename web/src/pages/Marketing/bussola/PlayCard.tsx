import { Link } from "react-router-dom";
import { Badge, Button, Progress } from "reactstrap";
import ActionsMenu from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";
import HiddenMoney from "Components/Common/HiddenMoney";
import type { CompassPlay } from "common/models/bussola.model";

/**
 * Uma jogada da semana (Bússola): o tipo e as lojas (avatar com ícone, como nos widgets do
 * Velzon), o título com verbo, o número grande, as barras com rótulo e valor (barras de
 * progresso) e o bloco "O que fazer" (O quê, Onde e Quando), com os botões. Vive dentro do
 * cartão "As 3 jogadas da semana" (moldura com borda, nunca cartão dentro de cartão).
 */
type Props = {
    companyId: number;
    play: CompassPlay;
    canAct: boolean;
    canActReason?: string | null;
    onCreate: (p: CompassPlay) => void;
    onSuggest: (p: CompassPlay) => void;
    onDetail: (p: CompassPlay) => void;
    /** Ignorar durante 4 semanas, ou (artigo) não voltar a sugerir. */
    onIgnore: (p: CompassPlay, permanent: boolean) => void;
};

/** A jogada é de um só artigo (pode ser excluído das sugestões). */
export const isSingleItem = (p: CompassPlay) => p.type !== "weak_period" && p.key.includes(":prod:");

export default function PlayCard({ companyId, play, canAct, canActReason, onCreate, onSuggest, onDetail, onIgnore }: Props) {
    const c = play.color;
    const noAct = canAct ? null : canActReason || "Sem permissão para criar publicações.";

    return (
        <div className="border rounded h-100 p-3 d-flex flex-column" data-testid={`play-${play.type}`}>
            <div className="d-flex align-items-start gap-3 mb-3">
                <div className="avatar-sm flex-shrink-0">
                    <span className={`avatar-title bg-${c}-subtle text-${c} rounded-2 fs-2`}><i className={play.icon} aria-hidden /></span>
                </div>
                <div className="flex-grow-1 overflow-hidden">
                    <p className="text-uppercase fw-medium text-muted text-truncate fs-12 mb-1">{play.type_label}</p>
                    <p className="text-muted fs-12 mb-1">{play.locations.join(", ")}</p>
                    <Badge color={play.confidence === "alta" ? "success-subtle" : "info-subtle"} className={`fw-normal text-${play.confidence === "alta" ? "success" : "info"}`}>
                        Confiança {play.confidence === "alta" ? "alta" : "média"}
                    </Badge>
                </div>
                <ActionsMenu size="sm" label={`Mais ações: ${play.title}`} disabled={!canAct} items={[
                    { label: "Ignorar durante 4 semanas", icon: "ri-eye-off-line", onClick: () => onIgnore(play, false) },
                    { label: "Não voltar a sugerir este artigo", icon: "ri-forbid-2-line", hidden: !isSingleItem(play), onClick: () => onIgnore(play, true) },
                ]} />
            </div>
            <h5 className="fs-16 mb-2">{play.title}</h5>
            <div className="d-flex align-items-baseline gap-2 mb-3">
                <span className={`fs-2 ff-secondary fw-semibold text-${c}`}>{play.number.value}</span>
                <span className="text-muted fs-12">{play.number.caption}</span>
            </div>
            <div className="vstack gap-2 mb-3">
                {play.bars.map((b, i) => (
                    <div key={i}>
                        <div className="d-flex justify-content-between gap-2 fs-12 mb-1">
                            <span className="text-truncate">{b.label}</span>
                            <span className="fw-medium text-nowrap">{b.hidden ? <HiddenMoney text="1 240 € contra 2 310 €" /> : b.value}</span>
                        </div>
                        <Progress value={Math.max(b.pct, b.pct > 0 ? 2 : 0)} color={b.color} className="progress-sm" aria-label={`${b.label}: ${b.hidden ? `${b.pct}%` : b.value}`} />
                    </div>
                ))}
            </div>
            <div className="bg-light-subtle border border-dashed rounded p-3 mt-auto" data-testid="play-todo">
                <p className="text-uppercase fw-semibold text-muted fs-11 mb-2">O que fazer</p>
                <dl className="row g-0 mb-0 fs-13">
                    <dt className="col-3 text-muted fw-normal">O quê</dt>
                    <dd className="col-9 mb-2">{play.what.text}</dd>
                    <dt className="col-3 text-muted fw-normal">Onde</dt>
                    <dd className="col-9 mb-2" data-testid="play-where">
                        {play.where.networks.map((n) => <span key={n.network} className="d-block">{n.label}: {n.format_label}</span>)}
                        {!play.where.connected && (
                            <span className="d-block text-muted fs-12 mt-1">
                                As redes ainda não estão ligadas. Ligue-as nas <Link to={`/companies/${companyId}?tab=integrations`} className="text-primary text-decoration-underline">Integrações</Link>.
                            </span>
                        )}
                    </dd>
                    <dt className="col-3 text-muted fw-normal">Quando</dt>
                    <dd className="col-9 mb-0">
                        <span className={play.when.is_today ? "fw-semibold text-warning" : "fw-medium"}>{play.when.label}</span>
                        {play.when.target && <span className="d-block text-muted fs-12">{play.when.target}</span>}
                    </dd>
                </dl>
            </div>
            <div className="d-flex flex-wrap gap-2 mt-3">
                <ReasonButton color="outline-primary" size="sm" onClick={() => onCreate(play)} reason={noAct}><i className="ri-add-line me-1" />Criar publicação</ReasonButton>
                <ReasonButton color="outline-primary" size="sm" onClick={() => onSuggest(play)} reason={noAct}><i className="ri-quill-pen-line me-1" />Sugerir texto</ReasonButton>
                <Button color="link" size="sm" className="px-1" onClick={() => onDetail(play)}>Ver detalhe</Button>
            </div>
        </div>
    );
}
