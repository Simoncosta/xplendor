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
                <Card
                    className="mb-0 border-0 overflow-hidden"
                    style={{
                        boxShadow: "0 16px 40px rgba(15, 23, 42, 0.08)",
                        background: "linear-gradient(180deg, #ffffff 0%, #fcfcfd 100%)",
                    }}
                >
                    <CardHeader
                        className="border-bottom-0"
                        style={{
                            padding: "1rem 1rem 0 1rem",
                            background: "linear-gradient(180deg, rgba(64,81,137,0.05) 0%, rgba(64,81,137,0.015) 100%)",
                        }}
                    >
                        <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3 px-2">
                            <div>
                                <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                    Briefing Editorial
                                </p>
                                <h5 className="mb-1 fw-semibold">
                                    <i className="ri-megaphone-line me-2 text-primary" />
                                    Inteligência de Marketing
                                </h5>
                                <p className="fs-12 text-muted mb-0">Ideias de conteúdo organizadas por intenção para este carro.</p>
                            </div>
                            <span className="badge bg-light text-muted fs-12 px-3 py-2">
                                {totalIdeas} ideia{totalIdeas !== 1 ? "s" : ""} gerada{totalIdeas !== 1 ? "s" : ""}
                            </span>
                        </div>

                        <ul
                            className="nav nav-tabs nav-tabs-custom nav-justified rounded-3 p-2 mb-0"
                            style={{
                                borderBottom: "none",
                                background: "#f8f9fa",
                                boxShadow: "inset 0 0 0 1px rgba(233,235,236,0.95)",
                                gap: "0.35rem",
                            }}
                        >
                            {tabs.map((tab) => (
                                <li className="nav-item" key={tab.key}>
                                    <button
                                        className="nav-link w-100"
                                        onClick={() => setActiveTab(tab.key)}
                                        style={{
                                            border: activeTab === tab.key ? "1px solid rgba(64,81,137,0.12)" : "1px solid transparent",
                                            borderBottom: "none",
                                            borderRadius: "0.75rem",
                                            background: activeTab === tab.key ? "#ffffff" : "transparent",
                                            color: activeTab === tab.key ? "#405189" : "#878a99",
                                            fontWeight: activeTab === tab.key ? 600 : 400,
                                            padding: "14px 16px",
                                            fontSize: "13px",
                                            boxShadow: activeTab === tab.key ? "0 6px 18px rgba(15, 23, 42, 0.06)" : "none",
                                            transition: "all 0.2s ease",
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

                    <CardBody style={{ padding: "1.5rem" }}>
                        {/* Sub-header da tab activa */}
                        <div className="mb-4 p-3 rounded-3" style={{ background: "#f8f9fa", border: "1px dashed #e9ebec" }}>
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
                                    <span className="badge badge-soft-success fs-11 d-flex align-items-center gap-1 px-3 py-2">
                                        <i className="ri-check-line" />
                                        Pronto a usar
                                    </span>
                                ) : (
                                    <span className="badge badge-soft-secondary fs-11 px-3 py-2">
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
