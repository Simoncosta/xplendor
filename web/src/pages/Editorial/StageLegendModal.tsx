import { Modal, ModalBody, ModalHeader } from "reactstrap";
import { STAGE_META, STAGE_ORDER, Stage, stageTextColor } from "common/models/editorialWorkflow.model";

/**
 * Legenda das etapas do calendário: cor, nome completo, o que significa e quem a move.
 * "Quem produz" são os utilizadores da empresa ou, no modo "Produção pela equipa", a
 * equipa XPLENDOR (as regras vêm do servidor; aqui só se explicam).
 */

const LEGEND: Record<Stage, { meaning: string; who: string }> = {
    idea: { meaning: "Proposta ainda por decidir, vinda das ideias geradas ou criada à mão.", who: "Quem produz passa-a a Planeamento." },
    planning: { meaning: "Tema, data, canal e formato decididos; o conteúdo ainda não existe.", who: "Quem produz passa-a a Produção ou devolve-a a Ideia." },
    production: { meaning: "Legenda, ficheiros e formato em preparação, por versões.", who: "Quem produz envia-a para revisão interna, para aprovação do cliente ou, se a empresa não pede aprovação, para Programado." },
    internal_review: { meaning: "Revisão interna antes do cliente, quando a empresa a exige.", who: "Outra pessoa que não o autor envia-a ao cliente ou devolve-a a Produção." },
    client_review: { meaning: "À espera da decisão do cliente sobre a versão enviada.", who: "O administrador da empresa ou um aprovador de conteúdos aprova (passa a Programado) ou pede alterações (volta a Produção)." },
    scheduled: { meaning: "Aprovada, com data e hora; alterar o conteúdo volta a pedir aprovação. Se a data e a hora passarem sem ser publicada, fica \"Atrasada\".", who: "Quem produz marca-a como publicada, com o link e a hora real." },
    published: { meaning: "Já está na rede, com o link registado.", who: "Passa sozinha a Análise ao fim de 7 dias; quem produz pode passá-la antes." },
    analysis: { meaning: "Resultados (alcance, interações e outros) e notas de aprendizagem; é a última etapa.", who: "Não avança mais." },
};

export default function StageLegendModal({ isOpen, toggle }: { isOpen: boolean; toggle: () => void }) {
    return (
        <Modal isOpen={isOpen} toggle={toggle} centered scrollable>
            <ModalHeader toggle={toggle}>Legenda das etapas</ModalHeader>
            <ModalBody>
                <ul className="list-unstyled mb-3">
                    {STAGE_ORDER.map((st) => {
                        const m = STAGE_META[st];
                        return (
                            <li key={st} className="d-flex gap-2 mb-3">
                                <span className="rounded px-1 fw-semibold flex-shrink-0 align-self-start text-center" style={{ background: m.hex, color: stageTextColor(st), fontSize: "0.7rem", minWidth: 52 }}>{m.short}</span>
                                <div className="fs-13">
                                    <div className="fw-semibold"><i className={`${m.icon} me-1`} style={{ color: m.hex }} />{m.label}</div>
                                    <div>{LEGEND[st].meaning}</div>
                                    <div className="text-muted"><i className="ri-user-line me-1" />{LEGEND[st].who}</div>
                                </div>
                            </li>
                        );
                    })}
                </ul>
                <p className="text-muted fs-12 mb-0">
                    <i className="ri-article-line me-1" />No canal Site, a cor mostra a etapa do artigo do Blog. Quem produz: os utilizadores da empresa ou, no modo "Produção pela equipa", a equipa XPLENDOR.
                </p>
            </ModalBody>
        </Modal>
    );
}
