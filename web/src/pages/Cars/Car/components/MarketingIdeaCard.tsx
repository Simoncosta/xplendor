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
    primary_texts?: string[];
    headlines?: string[];
    descriptions?: string[];
    hooks?: string[];
    caption?: string;
    cta?: string;
    content_pillars?: string[];
    why_now?: string;
}

interface Props {
    idea: MarketingIdea;
}

const sectionStyle = {
    border: "1px solid #e9ebec",
    borderRadius: "18px",
    background: "#fff",
    padding: "18px 20px",
} as const;

const labelClass = "fs-11 text-muted fw-semibold text-uppercase mb-2";

const formatIcon = (format: string): string => {
    const f = format.toLowerCase();
    if (f.includes("tiktok")) return "ri-tiktok-line";
    if (f.includes("instagram") || f.includes("post") || f.includes("carrossel")) return "ri-instagram-line";
    if (f.includes("reel") || f.includes("video curto")) return "ri-film-line";
    if (f.includes("video")) return "ri-video-line";
    if (f.includes("blog") || f.includes("artigo")) return "ri-article-line";
    if (f.includes("anuncio") || f.includes("ads")) return "ri-advertisement-line";
    if (f.includes("story") || f.includes("stories")) return "ri-image-line";
    if (f.includes("email")) return "ri-mail-line";
    if (f.includes("whatsapp")) return "ri-whatsapp-line";
    if (f.includes("comparativo")) return "ri-scales-line";
    return "ri-megaphone-line";
};

export default function MarketingIdeaCard({ idea }: Props) {
    const [copied, setCopied] = useState(false);

    const hooks = idea.hooks || [];
    const formats = idea.formats || [];
    const pillars = idea.content_pillars || [];
    const primaryTexts = idea.primary_texts || [];
    const headlines = idea.headlines || [];
    const descriptions = idea.descriptions || [];

    const copyText = async (value?: string | null, onSuccess?: () => void) => {
        if (!value) return;
        try {
            await navigator.clipboard.writeText(value);
            onSuccess?.();
        } catch {
            const ta = document.createElement("textarea");
            ta.value = value;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand("copy");
            document.body.removeChild(ta);
            onSuccess?.();
        }
    };

    const handleCopyCaption = async () => {
        await copyText(idea.caption, () => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    };

    return (
        <div className="d-grid gap-3">
            <section style={sectionStyle}>
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Estrategia editorial
                        </p>
                        <h5 className="mb-0 fw-semibold">Quem queremos ativar e com que narrativa</h5>
                    </div>
                </div>

                <Row className="g-3">
                    <Col md={6}>
                        <div className="h-100 rounded-3 bg-light-subtle" style={{ padding: "14px 16px" }}>
                            <p className={labelClass}>Publico-alvo</p>
                            <p className="fs-14 fw-medium mb-0 text-body">{idea.target_audience || "Nao definido"}</p>
                        </div>
                    </Col>
                    <Col md={6}>
                        <div className="h-100 rounded-3 bg-light-subtle" style={{ padding: "14px 16px" }}>
                            <p className={labelClass}>Objetivo</p>
                            <p className="fs-14 mb-0 text-body">{idea.goal || "Sem objetivo definido."}</p>
                        </div>
                    </Col>
                    <Col md={6}>
                        <div className="h-100 rounded-3 bg-light-subtle" style={{ padding: "14px 16px" }}>
                            <p className={labelClass}>Angulo</p>
                            <p className="fs-14 mb-0 text-body">{idea.angle || "Sem angulo definido."}</p>
                        </div>
                    </Col>
                    <Col md={6}>
                        <div className="h-100 rounded-3 bg-light-subtle" style={{ padding: "14px 16px" }}>
                            <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                                <p className={`${labelClass} mb-0`}>Pilares de conteudo</p>
                                <span className="badge bg-light text-dark fs-11">{pillars.length}</span>
                            </div>
                            {pillars.length > 0 ? (
                                <div className="d-flex flex-wrap gap-2">
                                    {pillars.map((pillar, idx) => (
                                        <span key={`${pillar}-${idx}`} className="badge rounded-pill bg-white text-body fs-11 px-3 py-2 border">
                                            {pillar}
                                        </span>
                                    ))}
                                </div>
                            ) : (
                                <p className="fs-12 text-muted mb-0">Ainda sem pilares definidos.</p>
                            )}
                        </div>
                    </Col>
                </Row>
            </section>

            <section style={sectionStyle}>
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Variacoes para anuncio
                        </p>
                        <h5 className="mb-0 fw-semibold">Assets prontos para campanha</h5>
                    </div>
                </div>

                <Row className="g-3">
                    <Col lg={4}>
                        <AssetList
                            title="Headlines"
                            items={headlines}
                            emptyLabel="Ainda sem headlines geradas."
                            onCopy={copyText}
                        />
                    </Col>
                    <Col lg={5}>
                        <AssetList
                            title="Primary texts"
                            items={primaryTexts}
                            emptyLabel="Ainda sem primary texts gerados."
                            onCopy={copyText}
                        />
                    </Col>
                    <Col lg={3}>
                        <AssetList
                            title="Descriptions"
                            items={descriptions}
                            emptyLabel="Ainda sem descriptions geradas."
                            onCopy={copyText}
                        />
                    </Col>
                </Row>
            </section>

            <section style={sectionStyle}>
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Execucao criativa
                        </p>
                        <h5 className="mb-0 fw-semibold">Pronto para adaptar, copiar e publicar</h5>
                    </div>
                </div>

                <Row className="g-3">
                    <Col lg={5}>
                        <div className="h-100 rounded-3 bg-light-subtle" style={{ padding: "14px 16px" }}>
                            <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                                <p className={`${labelClass} mb-0`}>Hooks</p>
                                <span className="badge bg-light text-dark fs-11">{hooks.length}</span>
                            </div>
                            {hooks.length > 0 ? (
                                <div className="vstack gap-3">
                                    {hooks.map((hook, idx) => (
                                        <div key={`${hook}-${idx}`} className="d-flex align-items-start gap-3">
                                            <span className="text-muted fs-12 fw-semibold" style={{ minWidth: 18 }}>
                                                {idx + 1}.
                                            </span>
                                            <span className="fs-14 text-body">{hook}</span>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="fs-12 text-muted mb-0">Ainda sem hooks sugeridos.</p>
                            )}
                        </div>
                    </Col>

                    <Col lg={7}>
                        <div className="d-grid gap-3 h-100">
                            <div className="rounded-3 bg-light-subtle" style={{ padding: "14px 16px" }}>
                                <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                                    <p className={`${labelClass} mb-0`}>Formatos</p>
                                    <span className="badge bg-light text-dark fs-11">{formats.length}</span>
                                </div>
                                {formats.length > 0 ? (
                                    <div className="d-flex flex-wrap gap-2">
                                        {formats.map((format, idx) => (
                                            <span key={`${format}-${idx}`} className="badge rounded-pill bg-white text-body px-3 py-2 border fs-12">
                                                <i className={`${formatIcon(format)} text-info me-2`} />
                                                {format}
                                            </span>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="fs-12 text-muted mb-0">Ainda sem formatos recomendados.</p>
                                )}
                            </div>

                            <div
                                className="rounded-3"
                                style={{
                                    padding: "16px 18px",
                                    background: "linear-gradient(180deg, #ffffff 0%, #f8fafc 100%)",
                                    border: "1px solid #e9ebec",
                                }}
                            >
                                <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                                    <div>
                                        <p className="fw-semibold text-muted text-uppercase mb-1" style={{ fontSize: 11, letterSpacing: "0.08em" }}>
                                            <i className="ri-quill-pen-line me-1 text-primary" />
                                            Caption
                                        </p>
                                        <p className="text-muted fs-12 mb-0">Texto pronto para usar ou adaptar</p>
                                    </div>
                                    {idea.caption && (
                                        <button
                                            className={`btn btn-sm ${copied ? "btn-soft-success" : "btn-soft-secondary"}`}
                                            style={{ fontSize: 12, padding: "6px 12px" }}
                                            onClick={handleCopyCaption}
                                        >
                                            <i className={`${copied ? "ri-check-line" : "ri-file-copy-line"} me-1`} />
                                            {copied ? "Copiado" : "Copiar"}
                                        </button>
                                    )}
                                </div>
                                <p className="fs-14 mb-0 text-body" style={{ lineHeight: 1.75 }}>
                                    {idea.caption || "Sem caption sugerida."}
                                </p>
                            </div>
                        </div>
                    </Col>
                </Row>
            </section>

            <section style={sectionStyle}>
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Fecho editorial
                        </p>
                        <h5 className="mb-0 fw-semibold">O que dizer e porque faz sentido agora</h5>
                    </div>
                </div>

                <Row className="g-3">
                    <Col md={6}>
                        <div className="h-100 rounded-3 bg-success-subtle" style={{ padding: "16px 18px" }}>
                            <p className="fs-11 text-success fw-semibold text-uppercase mb-2">CTA</p>
                            <p className="fs-14 mb-0 text-body">{idea.cta || "Sem CTA definido."}</p>
                        </div>
                    </Col>
                    <Col md={6}>
                        <div className="h-100 rounded-3 bg-light-subtle" style={{ padding: "16px 18px" }}>
                            <p className="fs-11 text-muted fw-semibold text-uppercase mb-2">Porque agora</p>
                            <p className="fs-14 mb-0 text-body">{idea.why_now || "Sem contexto adicional."}</p>
                        </div>
                    </Col>
                </Row>
            </section>
        </div>
    );
}

function AssetList({
    title,
    items,
    emptyLabel,
    onCopy,
}: {
    title: string;
    items: string[];
    emptyLabel: string;
    onCopy: (value?: string | null) => Promise<void>;
}) {
    return (
        <div className="h-100 rounded-3 bg-light-subtle" style={{ padding: "14px 16px" }}>
            <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                <p className="fs-11 text-muted fw-semibold text-uppercase mb-0">{title}</p>
                <span className="badge bg-light text-dark fs-11">{items.length}</span>
            </div>

            {items.length > 0 ? (
                <div className="vstack gap-2">
                    {items.map((item, index) => (
                        <div key={`${title}-${item}-${index}`} className="rounded-3 bg-white border" style={{ padding: "12px 14px" }}>
                            <div className="d-flex align-items-start justify-content-between gap-2">
                                <p className="mb-0 fs-13 text-body" style={{ lineHeight: 1.6 }}>
                                    {item}
                                </p>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-soft-secondary flex-shrink-0"
                                    onClick={() => void onCopy(item)}
                                >
                                    <i className="ri-file-copy-line" />
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <p className="fs-12 text-muted mb-0">{emptyLabel}</p>
            )}
        </div>
    );
}
