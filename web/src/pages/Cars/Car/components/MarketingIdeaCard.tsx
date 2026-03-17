import { useState } from "react";
import { Col, Row } from "reactstrap";

export type MarketingContentType = "sale" | "authority" | "engagement";

export interface MarketingIdea {
    content_type: MarketingContentType;
    title?: string;
    angle?: string;
    goal?: string;
    target_audience?: string;
    formats?: string[];
    hooks?: string[];
    caption?: string;
    cta?: string;
    content_pillars?: string[];
    why_now?: string;
}

interface Props {
    idea: MarketingIdea;
}

const boxStyle = {
    border: "1px dashed #e9ebec",
    borderRadius: "0.4rem",
    padding: "0.75rem",
    background: "#fff",
};

const mutedTitleClass = "fs-11 text-muted fw-semibold text-uppercase mb-1";

// ── Ícone contextual por formato ──────────────────────────────────────────────
const formatIcon = (format: string): string => {
    const f = format.toLowerCase();
    if (f.includes("tiktok")) return "ri-tiktok-line";
    if (f.includes("instagram") || f.includes("post") || f.includes("carrossel")) return "ri-instagram-line";
    if (f.includes("reel") || f.includes("vídeo curto") || f.includes("video curto")) return "ri-film-line";
    if (f.includes("vídeo") || f.includes("video")) return "ri-video-line";
    if (f.includes("blog") || f.includes("artigo")) return "ri-article-line";
    if (f.includes("anúncio") || f.includes("anuncio") || f.includes("ads")) return "ri-advertisement-line";
    if (f.includes("story") || f.includes("stories")) return "ri-image-line";
    if (f.includes("email")) return "ri-mail-line";
    if (f.includes("whatsapp")) return "ri-whatsapp-line";
    if (f.includes("comparativo") || f.includes("comparação")) return "ri-scales-line";
    return "ri-megaphone-line";
};

export default function MarketingIdeaCard({ idea }: Props) {
    const [copied, setCopied] = useState(false);

    const hooks = idea.hooks || [];
    const formats = idea.formats || [];
    const pillars = idea.content_pillars || [];

    const handleCopyCaption = async () => {
        if (!idea.caption) return;
        try {
            await navigator.clipboard.writeText(idea.caption);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // fallback para browsers sem clipboard API
            const ta = document.createElement("textarea");
            ta.value = idea.caption;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand("copy");
            document.body.removeChild(ta);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }
    };

    return (
        <Row className="g-3">

            {/* Título */}
            <Col xl={8}>
                <div style={boxStyle}>
                    <p className="fs-11 text-primary fw-semibold text-uppercase mb-1">Título</p>
                    <h5 className="fs-16 fw-semibold mb-0">{idea.title || "Sem título definido"}</h5>
                </div>
            </Col>

            {/* Público-alvo */}
            <Col xl={4}>
                <div className="h-100 d-flex flex-column justify-content-center" style={{ ...boxStyle, background: "#f8f9fa" }}>
                    <p className={mutedTitleClass}>Público-alvo</p>
                    <p className="fs-13 fw-medium mb-0">{idea.target_audience || "Não definido"}</p>
                </div>
            </Col>

            {/* Ângulo */}
            <Col md={6}>
                <div className="h-100" style={boxStyle}>
                    <p className={mutedTitleClass}>Ângulo</p>
                    <p className="fs-13 mb-0">{idea.angle || "Sem ângulo definido."}</p>
                </div>
            </Col>

            {/* Objetivo */}
            <Col md={6}>
                <div className="h-100" style={boxStyle}>
                    <p className={mutedTitleClass}>Objetivo</p>
                    <p className="fs-13 mb-0">{idea.goal || "Sem objetivo definido."}</p>
                </div>
            </Col>

            {/* Pilares */}
            <Col xs={12}>
                <div style={boxStyle}>
                    <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                        <p className={`${mutedTitleClass} mb-0`}>Pilares de Conteúdo</p>
                        <span className="badge badge-soft-primary fs-11">{pillars.length} pilar{pillars.length !== 1 ? "es" : ""}</span>
                    </div>
                    {pillars.length > 0 ? (
                        <div className="d-flex flex-wrap gap-2">
                            {pillars.map((pillar, idx) => (
                                <span key={`${pillar}-${idx}`} className="badge rounded-pill bg-light text-body fs-11 px-3 py-2">
                                    {pillar}
                                </span>
                            ))}
                        </div>
                    ) : (
                        <p className="fs-12 text-muted mb-0">Ainda sem pilares definidos.</p>
                    )}
                </div>
            </Col>

            {/* Hooks */}
            <Col lg={6}>
                <div className="h-100" style={boxStyle}>
                    <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                        <p className={`${mutedTitleClass} mb-0`}>Hooks</p>
                        <span className="badge badge-soft-warning fs-11">{hooks.length}</span>
                    </div>
                    {hooks.length > 0 ? (
                        <div className="vstack gap-2">
                            {hooks.map((hook, idx) => (
                                <div key={`${hook}-${idx}`} className="d-flex align-items-start gap-2">
                                    <span className="badge bg-warning-subtle text-warning fs-11 mt-1" style={{ minWidth: 24 }}>
                                        {idx + 1}
                                    </span>
                                    <span className="fs-13">{hook}</span>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="fs-12 text-muted mb-0">Ainda sem hooks sugeridos.</p>
                    )}
                </div>
            </Col>

            {/* Formatos */}
            <Col lg={6}>
                <div className="h-100" style={boxStyle}>
                    <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                        <p className={`${mutedTitleClass} mb-0`}>Formatos</p>
                        <span className="badge badge-soft-info fs-11">{formats.length}</span>
                    </div>
                    {formats.length > 0 ? (
                        <div className="vstack gap-2">
                            {formats.map((format, idx) => (
                                <div key={`${format}-${idx}`} className="d-flex align-items-center gap-2 fs-13">
                                    <i className={`${formatIcon(format)} text-info fs-16 flex-shrink-0`} />
                                    <span>{format}</span>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="fs-12 text-muted mb-0">Ainda sem formatos recomendados.</p>
                    )}
                </div>
            </Col>

            {/* Caption — com botão de copiar */}
            <Col xs={12}>
                <div className="p-3 rounded" style={{ background: "#f8f9fa", border: "1px dashed #e9ebec" }}>
                    <div className="d-flex align-items-center justify-content-between mb-2">
                        <p className="fw-semibold text-muted text-uppercase mb-0" style={{ fontSize: 11, letterSpacing: "0.5px" }}>
                            <i className="ri-quill-pen-line me-1 text-primary" />
                            Caption
                        </p>
                        {idea.caption && (
                            <button
                                className={`btn btn-sm ${copied ? "btn-soft-success" : "btn-soft-secondary"}`}
                                style={{ fontSize: 11, padding: "2px 10px" }}
                                onClick={handleCopyCaption}
                            >
                                <i className={`${copied ? "ri-check-line" : "ri-file-copy-line"} me-1`} />
                                {copied ? "Copiado!" : "Copiar"}
                            </button>
                        )}
                    </div>
                    <p className="fs-13 mb-0">{idea.caption || "Sem caption sugerida."}</p>
                </div>
            </Col>

            {/* CTA + Why Now */}
            <Col md={6}>
                <div className="h-100 p-3 rounded bg-success-subtle" style={{ border: "1px dashed #b8e6cc" }}>
                    <p className="fs-11 text-success fw-semibold text-uppercase mb-1">CTA</p>
                    <p className="fs-13 mb-0">{idea.cta || "Sem CTA definido."}</p>
                </div>
            </Col>

            <Col md={6}>
                <div className="h-100 p-3 rounded bg-light" style={{ border: "1px dashed #e9ebec" }}>
                    <p className="fs-11 text-muted fw-semibold text-uppercase mb-1">Porquê agora</p>
                    <p className="fs-13 mb-0">{idea.why_now || "Sem contexto adicional."}</p>
                </div>
            </Col>

        </Row>
    );
}