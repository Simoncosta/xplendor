import { useMemo, useState } from "react";
import { Card, CardBody, CardHeader, Col, Row } from "reactstrap";
import XButton from "Components/Common/XButton";
import MarketingIdeaCard, { MarketingContentType, MarketingIdea } from "./MarketingIdeaCard";

interface Props {
    ideas: MarketingIdea[];
    onGenerateIdeas?: () => void;
}

type TabKey = MarketingContentType;

const tabs: { key: TabKey; label: string; icon: string; color: string; description: string }[] = [
    { key: "sale", label: "Venda", icon: "ri-shopping-bag-3-line", color: "primary", description: "Conteúdo orientado a conversão e leads." },
    { key: "authority", label: "Autoridade", icon: "ri-award-line", color: "warning", description: "Ideias para reforçar credibilidade e expertise." },
    { key: "engagement", label: "Engagement", icon: "ri-heart-pulse-line", color: "danger", description: "Conteúdo pensado para alcance e interação." },
];

const shellStyle = {
    border: "1px dashed #e9ebec",
    borderRadius: "0.4rem",
    background: "#fff",
};

export default function TabMarketing({ ideas, onGenerateIdeas }: Props) {
    const [activeTab, setActiveTab] = useState<TabKey>("sale");

    const ideasByType = useMemo(() => ({
        sale: ideas.find((idea) => idea.content_type === "sale"),
        authority: ideas.find((idea) => idea.content_type === "authority"),
        engagement: ideas.find((idea) => idea.content_type === "engagement"),
    }), [ideas]);

    const activeIdea = ideasByType[activeTab];
    const activeMeta = tabs.find((tab) => tab.key === activeTab) || tabs[0];
    const totalIdeas = ideas.filter((idea) => tabs.some((tab) => tab.key === idea.content_type)).length;

    return (
        <Row className="g-3">
            <Col xs={12}>
                <Card className="mb-0">
                    <CardHeader className="p-0 border-bottom-0">
                        <div className="px-3 pt-3 pb-2 d-flex align-items-center justify-content-between gap-3 flex-wrap">
                            <div>
                                <h6 className="fs-13 fw-semibold mb-1">
                                    <i className="ri-megaphone-line me-2 text-primary" />
                                    Inteligência de Marketing
                                </h6>
                                <p className="fs-12 text-muted mb-0">Ideias de conteúdo organizadas por intenção para este carro.</p>
                            </div>
                            <span className="badge badge-soft-primary fs-12">
                                {totalIdeas} ideia{totalIdeas !== 1 ? "s" : ""} gerada{totalIdeas !== 1 ? "s" : ""}
                            </span>
                        </div>

                        <ul className="nav nav-tabs nav-tabs-custom nav-justified" style={{ borderBottom: "1px solid #e9ebec" }}>
                            {tabs.map((tab) => (
                                <li className="nav-item" key={tab.key}>
                                    <button
                                        className={`nav-link w-100 ${activeTab === tab.key ? "active" : ""}`}
                                        onClick={() => setActiveTab(tab.key)}
                                        style={{
                                            border: "none",
                                            borderBottom: activeTab === tab.key ? "2px solid #405189" : "2px solid transparent",
                                            borderRadius: 0,
                                            background: "transparent",
                                            color: activeTab === tab.key ? "#405189" : "#878a99",
                                            fontWeight: activeTab === tab.key ? 600 : 400,
                                            padding: "12px 16px",
                                            fontSize: "13px",
                                            transition: "all .2s",
                                            cursor: "pointer",
                                        }}
                                    >
                                        <i className={`${tab.icon} me-2`} />
                                        {tab.label}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </CardHeader>

                    <CardBody>
                        {/* Sub-header da tab activa */}
                        <div className="mb-3 p-3 rounded" style={{ background: "#f8f9fa", border: "1px dashed #e9ebec" }}>
                            <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap">
                                <div>
                                    <p className="fw-semibold text-muted text-uppercase mb-1" style={{ fontSize: 11, letterSpacing: "0.5px" }}>
                                        <i className={`${activeMeta.icon} me-1 text-${activeMeta.color}`} />
                                        {activeMeta.label}
                                    </p>
                                    <p className="fs-13 mb-0">{activeMeta.description}</p>
                                </div>
                                {/* Badge accionável em vez de "Ideia disponível" */}
                                {ideasByType[activeTab] ? (
                                    <span className="badge badge-soft-success fs-11 d-flex align-items-center gap-1">
                                        <i className="ri-check-line" />
                                        Pronto a usar
                                    </span>
                                ) : (
                                    <span className="badge badge-soft-secondary fs-11">
                                        Sem ideia
                                    </span>
                                )}
                            </div>
                        </div>

                        {activeIdea ? (
                            <MarketingIdeaCard idea={activeIdea} />
                        ) : (
                            <div className="text-center py-5 text-muted" style={shellStyle}>
                                <i className="ri-megaphone-line fs-1 d-block mb-3 text-primary" />
                                <h5 className="mb-2">Ainda não existem ideias de conteúdo para este tipo</h5>
                                <p className="mb-3 fs-13">Assim que houver contexto suficiente, esta área passa a sugerir ângulos, hooks e formatos.</p>
                                {onGenerateIdeas && (
                                    <XButton onClick={onGenerateIdeas}>Gerar ideias</XButton>
                                )}
                            </div>
                        )}
                    </CardBody>
                </Card>
            </Col>
        </Row>
    );
}