import React, { useMemo } from "react";
import { Card, CardBody, CardHeader, Input, Label } from "reactstrap";
import { SEO_LIMITS, SeoInput, plainText, seoChecklist } from "common/models/blog.model";

/**
 * SEO do artigo: título SEO, meta description e palavra-chave, pré-visualização no Google e
 * no cartão de partilha (Facebook, WhatsApp, LinkedIn) e checklist. Só "[VERIFICAR]" bloqueia
 * a aprovação; o resto são avisos. A confirmação "a primeira frase responde" é manual.
 */
type Props = {
    values: SeoInput;
    siteUrl: string | null;
    bannerUrl: string | null;
    disabled: boolean;
    onChange: (field: "meta_title" | "meta_description" | "focus_keyword" | "seo_answer_first_ok", value: any) => void;
};

const domainOf = (url: string | null) => {
    if (!url) return "o-seu-site.pt";
    try { return new URL(url.startsWith("http") ? url : `https://${url}`).hostname.replace(/^www\./, ""); } catch { return url; }
};
const cut = (s: string, n: number) => (s.length > n ? s.slice(0, n - 1).trimEnd() + "…" : s);

const Counter = ({ value, min, max }: { value: number; min: number; max: number }) => (
    <small className={value === 0 ? "text-muted" : value < min || value > max ? "text-warning" : "text-success"}>{value}/{max}</small>
);

const BlogSeoPanel = ({ values, siteUrl, bannerUrl, disabled, onChange }: Props) => {
    const checks = useMemo(() => seoChecklist(values), [values]);
    const domain = domainOf(siteUrl);
    const title = (values.meta_title || values.title || "Título do artigo").trim();
    const desc = (values.meta_description || values.excerpt || plainText(values.content).slice(0, 160) || "A meta description aparece aqui.").trim();
    const path = `${domain} › blog › ${values.slug || "endereco-do-artigo"}`;
    const okCount = checks.filter((c) => c.ok).length;

    return (
        <Card>
            <CardHeader className="d-flex align-items-center justify-content-between">
                <h5 className="card-title mb-0"><i className="ri-search-eye-line me-1" />SEO</h5>
                <span className="small text-muted">{okCount}/{checks.length} pontos</span>
            </CardHeader>
            <CardBody>
                <div className="mb-3">
                    <div className="d-flex justify-content-between"><Label className="mb-1">Título SEO</Label><Counter value={values.meta_title.length} min={SEO_LIMITS.titleMin} max={SEO_LIMITS.titleMax} /></div>
                    <Input value={values.meta_title} disabled={disabled} maxLength={255} placeholder={values.title || "Vazio: usa o título do artigo"} onChange={(e) => onChange("meta_title", e.target.value)} />
                </div>
                <div className="mb-3">
                    <div className="d-flex justify-content-between"><Label className="mb-1">Meta description</Label><Counter value={values.meta_description.length} min={SEO_LIMITS.descMin} max={SEO_LIMITS.descMax} /></div>
                    <textarea className="form-control" rows={3} disabled={disabled} maxLength={255} value={values.meta_description}
                        placeholder="Resumo de 1 ou 2 frases para o Google, com a palavra-chave." onChange={(e) => onChange("meta_description", e.target.value)} />
                </div>
                <div className="mb-3">
                    <Label className="mb-1">Palavra-chave principal</Label>
                    <Input value={values.focus_keyword} disabled={disabled} maxLength={100} placeholder="Ex.: autocaravana no inverno" onChange={(e) => onChange("focus_keyword", e.target.value)} />
                </div>

                <Label className="mb-1 text-muted small text-uppercase">No Google</Label>
                <div className="border rounded p-2 mb-3 bg-body">
                    <div className="small text-muted text-truncate">{path}</div>
                    <div className="text-primary fs-15 lh-sm">{cut(title, 60)}</div>
                    <div className="small text-muted">{cut(desc, 155)}</div>
                </div>

                <Label className="mb-1 text-muted small text-uppercase">Na partilha (Facebook, WhatsApp)</Label>
                <div className="border rounded overflow-hidden mb-3">
                    {bannerUrl
                        ? <img src={bannerUrl} alt="" className="w-100 object-fit-cover" style={{ aspectRatio: "1.91 / 1" }} />
                        : <div className="bg-light d-flex align-items-center justify-content-center text-muted small" style={{ aspectRatio: "1.91 / 1" }}>Sem banner: a partilha fica sem imagem</div>}
                    <div className="p-2 bg-light-subtle">
                        <div className="small text-muted text-uppercase">{domain}</div>
                        <div className="fw-semibold lh-sm">{cut(title, 70)}</div>
                        <div className="small text-muted">{cut(desc, 110)}</div>
                    </div>
                </div>

                <Label className="mb-1 text-muted small text-uppercase">Checklist</Label>
                <ul className="list-unstyled mb-0 small">
                    {checks.map((c) => (
                        <li key={c.key} className="d-flex align-items-start gap-2 py-1">
                            {c.manual ? (
                                <Input type="checkbox" className="mt-0 flex-shrink-0" id={`seo-${c.key}`} checked={c.ok} disabled={disabled}
                                    onChange={(e) => onChange("seo_answer_first_ok", e.target.checked)} />
                            ) : (
                                <i className={`flex-shrink-0 ${c.ok ? "ri-checkbox-circle-fill text-success" : c.blocking ? "ri-close-circle-fill text-danger" : "ri-error-warning-line text-warning"}`} />
                            )}
                            <label htmlFor={c.manual ? `seo-${c.key}` : undefined} className="mb-0">
                                {c.label}
                                {c.hint && <span className="text-muted"> ({c.hint})</span>}
                            </label>
                        </li>
                    ))}
                </ul>
            </CardBody>
        </Card>
    );
};

export default BlogSeoPanel;
