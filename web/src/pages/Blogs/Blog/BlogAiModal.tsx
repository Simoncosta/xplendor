import React, { useEffect, useRef, useState } from "react";
import { Badge, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Nav, NavItem, NavLink, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { createBlogAiDraft, getBlogAiContext, getBlogAiDraft } from "helpers/laravel_helper";
import { AUDIENCE_REASON, IBlogAiContext, IBlogAiDraft, IBlogAiResult } from "common/models/blog.model";

/**
 * "Ajudar a escrever": a IA propõe título, endereço, SEO, resumo e texto a partir de um tema
 * ou de uma publicação das redes (colar o texto). Corre em fila; aqui espera-se pelo
 * resultado. Nada é gravado: "Usar no artigo" só preenche o formulário para revisão.
 */
type Props = {
    isOpen: boolean;
    toggle: () => void;
    companyId: number;
    blogId: number | null;
    defaultKeyword: string;
    /** Tema já conhecido (ex.: publicação da Linha Editorial). */
    defaultTopic?: string;
    onApply: (result: IBlogAiResult) => void;
    onOpenBrandProfile: () => void;
};

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

const SOURCE_LABEL: Record<string, string> = { ga4: "GA4", meta: "Meta", sales: "Vendas" };
const POLL_MS = 2500;
const POLL_LIMIT = 72; // cerca de 3 minutos

const BlogAiModal = ({ isOpen, toggle, companyId, blogId, defaultKeyword, defaultTopic = "", onApply, onOpenBrandProfile }: Props) => {
    const [mode, setMode] = useState<"topic" | "from_post">("topic");
    const [context, setContext] = useState<IBlogAiContext | null>(null);
    const [topic, setTopic] = useState("");
    const [sourceText, setSourceText] = useState("");
    const [keyword, setKeyword] = useState(defaultKeyword);
    const [secondary, setSecondary] = useState("");
    const [notes, setNotes] = useState("");
    const [draft, setDraft] = useState<IBlogAiDraft | null>(null);
    const [busy, setBusy] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        if (!isOpen || !companyId) return;
        setKeyword((k) => k || defaultKeyword);
        setTopic((t) => t || defaultTopic);
        getBlogAiContext(companyId).then((r: any) => setContext(r.data)).catch(() => setContext(null));
    }, [isOpen, companyId, defaultKeyword, defaultTopic]);

    useEffect(() => () => { if (timer.current) clearTimeout(timer.current); }, []);

    const poll = (id: number, attempt = 0) => {
        timer.current = setTimeout(async () => {
            try {
                const r: any = await getBlogAiDraft(companyId, id);
                const d: IBlogAiDraft = r.data;
                setDraft(d);
                if (d.status === "done" || d.status === "error") {
                    setBusy(false);
                    setContext((c) => (c ? { ...c, used: d.used } : c));
                    return;
                }
                if (attempt >= POLL_LIMIT) {
                    setBusy(false);
                    toast.warning("O rascunho está a demorar. Volte a abrir o assistente dentro de alguns minutos.");
                    return;
                }
                poll(id, attempt + 1);
            } catch {
                setBusy(false);
                toast.error("Não foi possível obter o rascunho.");
            }
        }, POLL_MS);
    };

    const generate = async () => {
        setBusy(true);
        setDraft(null);
        try {
            const r: any = await createBlogAiDraft(companyId, {
                mode,
                topic: mode === "topic" ? topic : null,
                source_text: mode === "from_post" ? sourceText : null,
                keyword: keyword || null,
                secondary_keywords: secondary.split(",").map((s) => s.trim()).filter(Boolean),
                notes: notes || null,
                blog_id: blogId,
            });
            const d: IBlogAiDraft = r.data;
            setDraft(d);
            if (d.status === "done" || d.status === "error") {
                setBusy(false);
                setContext((c) => (c ? { ...c, used: d.used } : c));
            } else {
                poll(d.id);
            }
        } catch (e: any) {
            setBusy(false);
            toast.error(errorMessage(e, "Não foi possível pedir o rascunho."));
        }
    };

    const limitReached = !!context && context.used >= context.cap;
    const canGenerate = !busy && !limitReached && (mode === "topic" ? topic.trim().length > 2 : sourceText.trim().length > 20);
    const result = draft?.status === "done" ? draft.result : null;

    return (
        <Modal isOpen={isOpen} toggle={busy ? undefined : toggle} size="xl" centered scrollable>
            <ModalHeader toggle={busy ? undefined : toggle}>
                <i className="ri-magic-line me-1" />Ajudar a escrever
            </ModalHeader>
            <ModalBody>
                {context && (
                    <div className="d-flex flex-wrap align-items-center gap-2 mb-3 small">
                        <Badge color={limitReached ? "danger-subtle" : "light"} className={limitReached ? "text-danger" : "text-body"}>
                            {context.used}/{context.cap} rascunhos este mês
                        </Badge>
                        {context.sector ? <Badge color="light" className="text-body">Ramo: {context.sector}</Badge> : <Badge color="warning-subtle" className="text-warning">Ramo por definir</Badge>}
                        {context.has_brand_profile
                            ? <Badge color="success-subtle" className="text-success">Perfil da marca preenchido</Badge>
                            : <button type="button" className="btn btn-link btn-sm p-0" onClick={onOpenBrandProfile}>Perfil da marca por preencher: preencher agora</button>}
                    </div>
                )}

                {context && !context.audience.has_data && (
                    <div className="alert alert-warning small py-2">
                        <i className="ri-information-line me-1" />
                        <strong>Sem dados de público suficientes.</strong> A IA não vai presumir a idade nem o género dos leitores.
                        <div className="mt-1 text-muted">
                            {(["ga4", "meta", "sales"] as const).map((k) => {
                                const s = context.audience.sources[k];
                                const extra = s.minimum ? ` (${s.volume ?? 0} de ${s.minimum})` : "";
                                return <span key={k} className="me-3">{SOURCE_LABEL[k]}: {AUDIENCE_REASON[s.reason] ?? s.reason}{extra}</span>;
                            })}
                        </div>
                    </div>
                )}
                {context && context.audience.has_data && (
                    <div className="alert alert-info small py-2">
                        <i className="ri-group-line me-1" />O público medido entra no pedido:{" "}
                        {(["ga4", "meta", "sales"] as const).filter((k) => context.audience.sources[k].usable).map((k) => SOURCE_LABEL[k]).join(", ")}.
                    </div>
                )}

                <Nav tabs className="nav-tabs-custom mb-3">
                    <NavItem><NavLink href="#" active={mode === "topic"} onClick={(e) => { e.preventDefault(); setMode("topic"); }}>A partir de um tema</NavLink></NavItem>
                    <NavItem><NavLink href="#" active={mode === "from_post"} onClick={(e) => { e.preventDefault(); setMode("from_post"); }}>A partir de uma publicação</NavLink></NavItem>
                </Nav>

                <div className="row g-3">
                    <div className="col-lg-5">
                        {mode === "topic" ? (
                            <div className="mb-3">
                                <Label>Tema ou pergunta</Label>
                                <Input value={topic} maxLength={300} onChange={(e) => setTopic(e.target.value)} placeholder="Ex.: Como preparar a autocaravana para o inverno" />
                            </div>
                        ) : (
                            <div className="mb-3">
                                <Label>Texto da publicação</Label>
                                <textarea className="form-control" rows={7} maxLength={5000} value={sourceText} onChange={(e) => setSourceText(e.target.value)}
                                    placeholder="Cole aqui o texto da publicação do Instagram ou do Facebook." />
                                <div className="form-text">A IA desenvolve o texto sem acrescentar factos que não estejam na publicação.</div>
                            </div>
                        )}
                        <div className="mb-3">
                            <Label>Palavra-chave principal</Label>
                            <Input value={keyword} maxLength={100} onChange={(e) => setKeyword(e.target.value)} placeholder="Ex.: autocaravana inverno" />
                        </div>
                        <div className="mb-3">
                            <Label>Palavras-chave secundárias <span className="text-muted">(separadas por vírgulas)</span></Label>
                            <Input value={secondary} maxLength={400} onChange={(e) => setSecondary(e.target.value)} placeholder="Ex.: aquecimento, pneus de inverno" />
                        </div>
                        <div className="mb-3">
                            <Label>Indicações <span className="text-muted">(opcional)</span></Label>
                            <textarea className="form-control" rows={3} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)}
                                placeholder="Ex.: referir que fazemos a revisão na nossa oficina em Matosinhos." />
                        </div>
                        <button type="button" className="btn btn-primary w-100" disabled={!canGenerate} onClick={generate}>
                            {busy ? <><Spinner size="sm" className="me-1" />A escrever o rascunho…</> : <><i className="ri-magic-line me-1" />{result ? "Gerar outra vez" : "Gerar rascunho"}</>}
                        </button>
                        {limitReached && <div className="text-danger small mt-2">Atingiu o limite mensal de rascunhos com IA.</div>}
                        <p className="text-muted small mt-2 mb-0">O rascunho não é gravado nem publicado. Reveja sempre o texto antes de guardar.</p>
                    </div>

                    <div className="col-lg-7">
                        {draft?.status === "error" && <div className="alert alert-danger">{draft.error_message}</div>}
                        {busy && !result && (
                            <div className="h-100 d-flex flex-column align-items-center justify-content-center text-muted py-5">
                                <Spinner className="mb-2" />Normalmente demora menos de um minuto.
                            </div>
                        )}
                        {!busy && !result && draft?.status !== "error" && (
                            <div className="h-100 d-flex align-items-center justify-content-center text-muted py-5 text-center">
                                O rascunho proposto aparece aqui.
                            </div>
                        )}
                        {result && (
                            <div className="border rounded p-3">
                                {result.has_markers && (
                                    <div className="alert alert-warning small py-2">
                                        <i className="ri-error-warning-line me-1" />O texto tem pontos marcados com "[VERIFICAR]". Confirme-os antes de enviar para aprovação.
                                    </div>
                                )}
                                <div className="small text-muted">Título</div>
                                <h5>{result.title}</h5>
                                <div className="small text-muted">Endereço</div>
                                <p className="font-monospace small">/{result.slug}</p>
                                <div className="small text-muted">Título SEO e meta description</div>
                                <p className="mb-1 fw-medium">{result.meta_title}</p>
                                <p className="small">{result.meta_description}</p>
                                <div className="small text-muted">Resumo</div>
                                <p>{result.excerpt}</p>
                                <div className="small text-muted mb-1">Texto</div>
                                <div className="blog-ai-preview border-top pt-2" style={{ maxHeight: 360, overflowY: "auto" }} dangerouslySetInnerHTML={{ __html: result.content }} />
                                {result.review_notes.length > 0 && (
                                    <>
                                        <div className="small text-muted mt-3">A confirmar pelo revisor</div>
                                        <ul className="small mb-0">{result.review_notes.map((n, i) => <li key={i}>{n}</li>)}</ul>
                                    </>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </ModalBody>
            <ModalFooter>
                <button type="button" className="btn btn-light" onClick={toggle} disabled={busy}>Fechar</button>
                <button type="button" className="btn btn-success" disabled={!result || busy} onClick={() => result && onApply(result)}>
                    <i className="ri-check-line me-1" />Usar no artigo
                </button>
            </ModalFooter>
        </Modal>
    );
};

export default BlogAiModal;
