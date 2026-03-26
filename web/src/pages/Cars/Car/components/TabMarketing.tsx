import { useMemo, useState } from "react";
import { Col, Row } from "reactstrap";
import XButton from "Components/Common/XButton";
import MarketingIdeaCard, { MarketingContentType, MarketingIdea } from "./MarketingIdeaCard";

interface Props {
    ideas: MarketingIdea[];
    onGenerateIdeas?: () => void;
}

type TabKey = MarketingContentType;

const tabs: { key: TabKey; label: string; icon: string; color: string; description: string }[] = [
    { key: "sale", label: "Venda", icon: "ri-shopping-bag-3-line", color: "primary", description: "Conteudo orientado a conversao e leads." },
    { key: "authority", label: "Autoridade", icon: "ri-award-line", color: "warning", description: "Ideias para reforcar credibilidade e expertise." },
    { key: "engagement", label: "Engagement", icon: "ri-heart-pulse-line", color: "danger", description: "Conteudo pensado para alcance e interacao." },
];

const sectionStyle = {
    border: "1px solid #e9ebec",
    borderRadius: "18px",
    background: "#fff",
} as const;

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
                <section style={sectionStyle}>
                    <div style={{ padding: "18px 20px 0 20px", borderBottom: "1px solid #e9ebec" }}>
                        <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                            <div>
                                <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                    Briefing editorial
                                </p>
                                <h5 className="mb-1 fw-semibold">Inteligencia de Marketing</h5>
                                <p className="fs-13 text-muted mb-0">Um briefing por intencao, pronto para leitura, adaptacao e execucao.</p>
                            </div>
                            <span className="badge bg-light text-muted fs-12 px-3 py-2">
                                {totalIdeas} ideia{totalIdeas !== 1 ? "s" : ""} gerada{totalIdeas !== 1 ? "s" : ""}
                            </span>
                        </div>

                        <div className="d-flex gap-2 flex-wrap pb-3">
                            {tabs.map((tab) => {
                                const isActive = activeTab === tab.key;
                                return (
                                    <button
                                        key={tab.key}
                                        type="button"
                                        onClick={() => setActiveTab(tab.key)}
                                        className="btn"
                                        style={{
                                            border: isActive ? "1px solid #dbe4ff" : "1px solid #e9ebec",
                                            borderRadius: "999px",
                                            background: isActive ? "#f5f8ff" : "#fff",
                                            color: isActive ? "#405189" : "#6c757d",
                                            fontWeight: isActive ? 600 : 500,
                                            padding: "10px 14px",
                                            fontSize: "13px",
                                        }}
                                    >
                                        <i className={`${tab.icon} me-2 text-${isActive ? tab.color : "muted"}`} />
                                        {tab.label}
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    <div style={{ padding: "18px 20px 20px 20px" }}>
                        <section
                            className="mb-4"
                            style={{
                                border: "1px solid #e9ebec",
                                borderRadius: "18px",
                                background: "linear-gradient(180deg, #ffffff 0%, #f8fafc 100%)",
                                padding: "18px 20px",
                            }}
                        >
                            <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                                <div>
                                    <div className="d-flex align-items-center gap-2 flex-wrap mb-2">
                                        <span className={`badge bg-${activeMeta.color}-subtle text-${activeMeta.color} px-3 py-2`}>
                                            <i className={`${activeMeta.icon} me-1`} />
                                            {activeMeta.label}
                                        </span>
                                        {activeIdea ? (
                                            <span className="badge bg-success-subtle text-success px-3 py-2">Pronto a usar</span>
                                        ) : (
                                            <span className="badge bg-light text-muted px-3 py-2">Sem ideia</span>
                                        )}
                                    </div>
                                    <p className="text-muted fs-13 mb-2">{activeMeta.description}</p>
                                    <h3 className="mb-0 fw-semibold" style={{ maxWidth: 860 }}>
                                        {activeIdea?.title || `Briefing de ${activeMeta.label.toLowerCase()} para esta viatura`}
                                    </h3>
                                </div>
                            </div>
                        </section>

                        {activeIdea ? (
                            <MarketingIdeaCard idea={activeIdea} />
                        ) : (
                            <div
                                className="text-center py-5 text-muted"
                                style={{ border: "1px solid #e9ebec", borderRadius: 16, background: "#fff" }}
                            >
                                <i className="ri-megaphone-line fs-1 d-block mb-3 text-primary" />
                                <h5 className="mb-2">Ainda nao existem ideias de conteudo para este tipo</h5>
                                <p className="mb-3 fs-13">Assim que houver contexto suficiente, esta area passa a sugerir angulos, hooks e formatos.</p>
                                {onGenerateIdeas && (
                                    <XButton onClick={onGenerateIdeas}>Gerar ideias</XButton>
                                )}
                            </div>
                        )}
                    </div>
                </section>
            </Col>
        </Row>
    );
}
