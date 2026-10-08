import { Badge, Button, Card, CardBody, Progress } from "reactstrap";
import ReasonButton from "Components/Common/ReasonButton";
import type { CompassPlay } from "common/models/bussola.model";

/**
 * Uma jogada da semana (Bússola): o tipo e as lojas (avatar com ícone, como nos widgets do
 * Velzon), o título com verbo, o número grande, as barras com rótulo e valor (barras de
 * progresso) e o bloco "O que fazer" (O quê, Onde e Quando), com os botões.
 */
type Props = {
    play: CompassPlay;
    canAct: boolean;
    canActReason?: string | null;
    onCreate: (p: CompassPlay) => void;
    onSuggest: (p: CompassPlay) => void;
    onDetail: (p: CompassPlay) => void;
};

export default function PlayCard({ play, canAct, canActReason, onCreate, onSuggest, onDetail }: Props) {
    const c = play.color;
    const where = play.where.networks.map((n) => `${n.label}, ${n.format_label}`).join("; ");

    return (
        <Card className="h-100 mb-0" data-testid={`play-${play.type}`}>
            <CardBody className="d-flex flex-column">
                <div className="d-flex align-items-center gap-3 mb-3">
                    <div className="avatar-sm flex-shrink-0">
                        <span className={`avatar-title bg-${c}-subtle text-${c} rounded-2 fs-2`}><i className={play.icon} aria-hidden /></span>
                    </div>
                    <div className="flex-grow-1 overflow-hidden">
                        <p className="text-uppercase fw-medium text-muted text-truncate fs-12 mb-1">{play.type_label}</p>
                        <p className="text-muted text-truncate fs-12 mb-0">{play.locations.join(", ")}</p>
                    </div>
                    <Badge color={play.confidence === "alta" ? "success-subtle" : "info-subtle"} className={`fw-normal text-${play.confidence === "alta" ? "success" : "info"}`}>
                        Confiança {play.confidence === "alta" ? "alta" : "média"}
                    </Badge>
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
                                <span className="fw-medium text-nowrap">{b.value}</span>
                            </div>
                            <Progress value={Math.max(b.pct, b.pct > 0 ? 2 : 0)} color={b.color} className="progress-sm" aria-label={`${b.label}: ${b.value}`} />
                        </div>
                    ))}
                </div>
                <div className="bg-light-subtle border border-dashed rounded p-3 mt-auto" data-testid="play-todo">
                    <p className="text-uppercase fw-semibold text-muted fs-11 mb-2">O que fazer</p>
                    <dl className="row g-0 mb-0 fs-13">
                        <dt className="col-3 text-muted fw-normal">O quê</dt>
                        <dd className="col-9 mb-2">{play.what.text}</dd>
                        <dt className="col-3 text-muted fw-normal">Onde</dt>
                        <dd className="col-9 mb-2">{where}{play.where.note && <span className="d-block text-muted fs-12">{play.where.note}</span>}</dd>
                        <dt className="col-3 text-muted fw-normal">Quando</dt>
                        <dd className="col-9 mb-0">
                            <span className={play.when.is_today ? "fw-semibold text-warning" : "fw-medium"}>{play.when.label}</span>
                            {play.when.target && <span className="d-block text-muted fs-12">{play.when.target}</span>}
                        </dd>
                    </dl>
                </div>
                <div className="d-flex flex-wrap gap-2 mt-3">
                    <ReasonButton color="outline-primary" size="sm" onClick={() => onCreate(play)} reason={canAct ? null : canActReason || "Sem permissão para criar publicações."}>
                        <i className="ri-add-line me-1" />Criar publicação
                    </ReasonButton>
                    <ReasonButton color="outline-primary" size="sm" onClick={() => onSuggest(play)} reason={canAct ? null : canActReason || "Sem permissão para criar publicações."}>
                        <i className="ri-quill-pen-line me-1" />Sugerir texto
                    </ReasonButton>
                    <Button color="link" size="sm" className="px-1" onClick={() => onDetail(play)}>Ver detalhe</Button>
                </div>
            </CardBody>
        </Card>
    );
}
