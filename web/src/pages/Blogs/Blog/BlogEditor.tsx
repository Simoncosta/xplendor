import React, { useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { FormikProvider, useFormik } from "formik";
import CreatableSelect from "react-select/creatable";
import { Badge, Card, CardBody, CardHeader, Col, Container, Input, InputGroup, InputGroupText, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import XInputTextareaQuill from "Components/Common/XInputTextareaQuill";
import {
    approveBlog, blogBackToDraft, createBlog, deleteBlog, deleteBlogBanner, requestBlogChanges, showBlog, submitBlog, updateBlog,
} from "helpers/laravel_helper";
import { BLOG_STATUS_META, IBlogAiResult, IBlogPost, blogImage, fmtDateTime, hasMarker, slugify } from "common/models/blog.model";
import BlogSeoPanel from "./BlogSeoPanel";
import BlogAiModal from "./BlogAiModal";
import BrandProfileModal from "../BrandProfileModal";

/**
 * Criar e editar um artigo do blog. Conteúdo (o HTML é limpo no servidor ao gravar e ao
 * mostrar), banner, endereço (fixo depois de publicado), SEO com pré-visualizações e
 * checklist, "Ajudar a escrever" (IA, sempre revista por um humano) e o fluxo:
 * rascunho → em revisão → aprovado (agendado) → publicado. As permissões vêm do servidor.
 */
const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};
const readAuth = () => {
    try { return JSON.parse(sessionStorage.getItem("authUser") || "null") ?? {}; } catch { return {}; }
};

interface Form {
    title: string; subtitle: string; slug: string; excerpt: string; content: string; category: string; tags: string[];
    meta_title: string; meta_description: string; focus_keyword: string; seo_answer_first_ok: boolean;
}
const EMPTY: Form = {
    title: "", subtitle: "", slug: "", excerpt: "", content: "", category: "", tags: [],
    meta_title: "", meta_description: "", focus_keyword: "", seo_answer_first_ok: false,
};
const fromBlog = (b: IBlogPost): Form => ({
    title: b.title ?? "", subtitle: b.subtitle ?? "", slug: b.slug ?? "", excerpt: b.excerpt ?? "", content: b.content ?? "",
    category: b.category ?? "", tags: b.tags ?? [], meta_title: b.meta_title ?? "", meta_description: b.meta_description ?? "",
    focus_keyword: b.focus_keyword ?? "", seo_answer_first_ok: !!b.seo_answer_first_ok,
});

const QUILL_MODULES = {
    toolbar: [
        [{ header: [2, 3, false] }],
        ["bold", "italic", "underline"],
        [{ list: "ordered" }, { list: "bullet" }],
        ["link"],
        ["clean"],
    ],
};

/** "2026-11-20T10:30" na hora local do browser. */
const toLocalInput = (d: Date) => {
    const p = (n: number) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
};

const BlogEditor = () => {
    const { id } = useParams();
    const navigate = useNavigate();
    const isNew = !id;
    const auth = useMemo(readAuth, []);
    const companyId = Number(auth.company_id || 0);

    const [blog, setBlog] = useState<IBlogPost | null>(null);
    const [loading, setLoading] = useState(!isNew);
    const [saving, setSaving] = useState(false);
    const [acting, setActing] = useState(false);
    const [bannerFile, setBannerFile] = useState<File | null>(null);
    const [bannerPreview, setBannerPreview] = useState<string | null>(null);
    const [slugTouched, setSlugTouched] = useState(false);
    const [aiOpen, setAiOpen] = useState(false);
    const [profileOpen, setProfileOpen] = useState(false);
    const [approveOpen, setApproveOpen] = useState(false);
    const [publishMode, setPublishMode] = useState<"now" | "schedule">("now");
    const [publishAt, setPublishAt] = useState(toLocalInput(new Date(Date.now() + 24 * 3600 * 1000)));
    const [changesOpen, setChangesOpen] = useState(false);
    const [changesNote, setChangesNote] = useState("");

    document.title = `${isNew ? "Novo artigo" : blog?.title || "Artigo"} | Xplendor`;

    const formik = useFormik<Form>({ initialValues: EMPTY, enableReinitialize: true, onSubmit: () => undefined });
    const v = formik.values;

    useEffect(() => {
        if (isNew || !companyId) return;
        setLoading(true);
        showBlog(companyId, Number(id))
            .then((r: any) => { setBlog(r.data); formik.resetForm({ values: fromBlog(r.data) }); setSlugTouched(true); })
            .catch(() => toast.error("Artigo não encontrado."))
            .finally(() => setLoading(false));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [companyId, id, isNew]);

    useEffect(() => () => { if (bannerPreview) URL.revokeObjectURL(bannerPreview); }, [bannerPreview]);

    const perms = blog?.permissions;
    const canEdit = isNew || !!perms?.can_edit;
    const slugLocked = !!perms?.slug_locked;
    const dirty = formik.dirty || !!bannerFile;
    const status = blog?.status ?? "draft";
    const statusMeta = BLOG_STATUS_META[status];
    const bannerUrl = bannerPreview || blogImage(blog?.banner);
    const siteBase = (blog?.site_url || "").replace(/\/+$/, "");

    const set = (field: keyof Form, value: any) => formik.setFieldValue(field, value);

    const onTitle = (title: string) => {
        set("title", title);
        if (!slugTouched && !slugLocked) set("slug", slugify(title));
    };

    const formData = () => {
        const fd = new FormData();
        (["title", "subtitle", "slug", "excerpt", "content", "category", "meta_title", "meta_description", "focus_keyword"] as const)
            .forEach((k) => fd.append(k, (v[k] as string) ?? ""));
        fd.append("seo_answer_first_ok", v.seo_answer_first_ok ? "1" : "0");
        v.tags.forEach((t, i) => fd.append(`tags[${i}]`, t));
        if (bannerFile) fd.append("banner", bannerFile);
        return fd;
    };

    /** Grava (cria ou altera). Devolve o artigo atualizado, ou null se falhar. */
    const save = async (quiet = false): Promise<IBlogPost | null> => {
        if (!v.title.trim()) { toast.error("O título é obrigatório."); return null; }
        setSaving(true);
        try {
            const r: any = isNew ? await createBlog(companyId, formData()) : await updateBlog(companyId, Number(id), formData());
            const saved: IBlogPost = r.data;
            setBlog(saved);
            formik.resetForm({ values: fromBlog(saved) });
            setBannerFile(null);
            setBannerPreview(null);
            if (!quiet) toast.success(isNew ? "Artigo criado." : "Artigo guardado.");
            if (isNew) navigate(`/blogs/${saved.id}`, { replace: true });
            return saved;
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível guardar o artigo."));
            return null;
        } finally {
            setSaving(false);
        }
    };

    /** Ações do fluxo: grava primeiro se houver alterações por guardar. */
    const act = async (run: (blogId: number) => Promise<any>, ok: string) => {
        let current = blog;
        if (dirty || !current) {
            current = await save(true);
            if (!current) return;
        }
        setActing(true);
        try {
            const r: any = await run(current.id);
            setBlog(r.data);
            formik.resetForm({ values: fromBlog(r.data) });
            toast.success(ok);
            return true;
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível concluir a ação."));
            return false;
        } finally {
            setActing(false);
        }
    };

    const onApprove = async () => {
        const at = publishMode === "schedule" ? new Date(publishAt) : null;
        if (at && (isNaN(at.getTime()) || at.getTime() < Date.now())) { toast.error("Escolha uma data futura para agendar."); return; }
        const done = await act((bid) => approveBlog(companyId, bid, at ? at.toISOString() : null), at ? "Artigo aprovado e agendado." : "Artigo aprovado e publicado.");
        if (done) setApproveOpen(false);
    };

    const onRequestChanges = async () => {
        if (!changesNote.trim()) { toast.error("Indique as alterações pedidas."); return; }
        const done = await act((bid) => requestBlogChanges(companyId, bid, changesNote.trim()), "Artigo devolvido com pedido de alterações.");
        if (done) { setChangesOpen(false); setChangesNote(""); }
    };

    const onDelete = async () => {
        if (!blog || !window.confirm("Apagar este artigo? Esta ação não pode ser desfeita.")) return;
        try {
            await deleteBlog(companyId, blog.id);
            toast.success("Artigo apagado.");
            navigate("/blogs");
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível apagar o artigo."));
        }
    };

    const onRemoveBanner = async () => {
        if (bannerFile) { setBannerFile(null); setBannerPreview(null); return; }
        if (!blog) return;
        try {
            const r: any = await deleteBlogBanner(companyId, blog.id);
            setBlog(r.data);
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível remover o banner."));
        }
    };

    const applyAi = (res: IBlogAiResult) => {
        const hasText = v.content.replace(/<[^>]+>/g, "").trim().length > 0;
        if (hasText && !window.confirm("Substituir o texto atual pelo rascunho da IA?")) return;
        formik.setValues({
            ...v,
            title: res.title || v.title,
            slug: slugLocked ? v.slug : res.slug || v.slug,
            meta_title: res.meta_title || v.meta_title,
            meta_description: res.meta_description || v.meta_description,
            excerpt: res.excerpt || v.excerpt,
            content: res.content || v.content,
            seo_answer_first_ok: false,
        });
        setSlugTouched(true);
        setAiOpen(false);
        toast.info("Rascunho da IA aplicado. Reveja o texto e guarde.");
    };

    const markers = [v.title, v.subtitle, v.excerpt, v.content, v.meta_title, v.meta_description].some(hasMarker);

    if (loading) {
        return <div className="page-content"><Container fluid><div className="text-center py-5"><Spinner /></div></Container></div>;
    }

    return (
        <React.Fragment>
            <ToastContainer />
            <div className="page-content">
                <Container fluid>
                    <FormikProvider value={formik}>
                        <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <div className="d-flex align-items-center gap-2">
                                <Link to="/blogs" className="btn btn-sm btn-light"><i className="ri-arrow-left-line" /></Link>
                                <h4 className="mb-0">{isNew ? "Novo artigo" : "Editar artigo"}</h4>
                                {!isNew && <Badge color={`${statusMeta.color}-subtle`} className={`text-${statusMeta.color} fs-12`}><i className={`${statusMeta.icon} me-1`} />{statusMeta.label}</Badge>}
                                {dirty && <span className="small text-warning"><i className="ri-edit-circle-line me-1" />Alterações por guardar</span>}
                            </div>
                            <div className="d-flex gap-2">
                                {canEdit && (
                                    <button type="button" className="btn btn-soft-primary" onClick={() => setAiOpen(true)}>
                                        <i className="ri-magic-line me-1" />Ajudar a escrever
                                    </button>
                                )}
                                {canEdit && (
                                    <button type="button" className="btn btn-success" onClick={() => save()} disabled={saving || acting || (!dirty && !isNew)}>
                                        {saving ? <Spinner size="sm" className="me-1" /> : <i className="ri-save-line me-1" />}Guardar
                                    </button>
                                )}
                            </div>
                        </div>

                        {blog?.review_note && status === "draft" && (
                            <div className="alert alert-warning">
                                <i className="ri-chat-1-line me-1" /><strong>Alterações pedidas pelo administrador:</strong> {blog.review_note}
                            </div>
                        )}
                        {!isNew && !canEdit && (
                            <div className="alert alert-info">
                                <i className="ri-lock-line me-1" />Este artigo está {statusMeta.label.toLowerCase()}. Só o administrador da empresa pode alterá-lo ou devolvê-lo a rascunho.
                            </div>
                        )}

                        <Row>
                            <Col xl={8}>
                                <Card>
                                    <CardBody>
                                        <div className="mb-3">
                                            <Label>Título <span className="text-danger">*</span></Label>
                                            <Input value={v.title} disabled={!canEdit} maxLength={255} onChange={(e) => onTitle(e.target.value)} placeholder="Ex.: Como preparar a autocaravana para o inverno" />
                                        </div>
                                        <div className="mb-3">
                                            <Label>Subtítulo</Label>
                                            <Input value={v.subtitle} disabled={!canEdit} maxLength={255} onChange={(e) => set("subtitle", e.target.value)} />
                                        </div>
                                        <div className="mb-3">
                                            <Label>Endereço (slug)</Label>
                                            <InputGroup>
                                                <InputGroupText className="small">{siteBase ? `${siteBase.replace(/^https?:\/\//, "")}/blog/` : "/blog/"}</InputGroupText>
                                                <Input value={v.slug} disabled={!canEdit || slugLocked} maxLength={180}
                                                    onChange={(e) => { setSlugTouched(true); set("slug", e.target.value.toLowerCase().replace(/\s+/g, "-")); }}
                                                    onBlur={() => set("slug", slugify(v.slug))} />
                                                {!slugLocked && canEdit && (
                                                    <button type="button" className="btn btn-light" title="Gerar a partir do título" onClick={() => { set("slug", slugify(v.title)); setSlugTouched(false); }}>
                                                        <i className="ri-refresh-line" />
                                                    </button>
                                                )}
                                            </InputGroup>
                                            <div className="form-text">
                                                {slugLocked
                                                    ? <><i className="ri-lock-line me-1" />Fixo: o artigo já foi publicado e o endereço não muda, para não quebrar ligações.</>
                                                    : "Fica fixo a partir da primeira publicação. Se ficar vazio, é gerado a partir do título."}
                                            </div>
                                        </div>
                                        <div className="mb-3">
                                            <Label>Resumo</Label>
                                            <textarea className="form-control" rows={2} maxLength={500} disabled={!canEdit} value={v.excerpt} onChange={(e) => set("excerpt", e.target.value)}
                                                placeholder="Uma ou duas frases para a lista de artigos." />
                                        </div>
                                        <div className="mb-3">
                                            <Label>Banner</Label>
                                            {bannerUrl && (
                                                <div className="position-relative mb-2" style={{ maxWidth: 420 }}>
                                                    <img src={bannerUrl} alt="" className="img-fluid rounded border" />
                                                    {canEdit && (
                                                        <button type="button" className="btn btn-sm btn-danger position-absolute top-0 end-0 m-1" onClick={onRemoveBanner} title="Remover">
                                                            <i className="ri-delete-bin-line" />
                                                        </button>
                                                    )}
                                                </div>
                                            )}
                                            {canEdit && (
                                                <Input type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => {
                                                    const f = e.currentTarget.files?.[0];
                                                    if (!f) return;
                                                    if (f.size > 4 * 1024 * 1024) { toast.error("O banner não pode ter mais de 4 MB."); return; }
                                                    setBannerFile(f);
                                                    setBannerPreview(URL.createObjectURL(f));
                                                }} />
                                            )}
                                            <div className="form-text">Recomendado: 1200 x 630 px. É convertido para WebP e usado também na partilha nas redes.</div>
                                        </div>
                                        <div className="mb-3">
                                            {canEdit ? (
                                                <XInputTextareaQuill name="content" label="Texto" height={420} modules={QUILL_MODULES} />
                                            ) : (
                                                <>
                                                    <Label>Texto</Label>
                                                    <div className="border rounded p-3" dangerouslySetInnerHTML={{ __html: v.content }} />
                                                </>
                                            )}
                                            {markers && <div className="small text-danger mt-1"><i className="ri-error-warning-line me-1" />Há "[VERIFICAR]" por resolver: confirme esses pontos antes da aprovação.</div>}
                                        </div>
                                        <Row>
                                            <Col md={5}>
                                                <Label>Categoria</Label>
                                                <Input value={v.category} disabled={!canEdit} maxLength={100} onChange={(e) => set("category", e.target.value)} />
                                            </Col>
                                            <Col md={7}>
                                                <Label>Etiquetas</Label>
                                                <CreatableSelect
                                                    isMulti
                                                    isDisabled={!canEdit}
                                                    placeholder="Escreva e carregue em Enter"
                                                    formatCreateLabel={(t) => `Acrescentar "${t}"`}
                                                    noOptionsMessage={() => "Escreva e carregue em Enter"}
                                                    value={v.tags.map((t) => ({ label: t, value: t }))}
                                                    onChange={(opts) => set("tags", (opts || []).map((o: any) => o.value))}
                                                    onCreateOption={(t) => { const n = t.trim(); if (n && !v.tags.includes(n)) set("tags", [...v.tags, n]); }}
                                                />
                                            </Col>
                                        </Row>
                                    </CardBody>
                                </Card>
                            </Col>

                            <Col xl={4}>
                                <Card>
                                    <CardHeader><h5 className="card-title mb-0"><i className="ri-send-plane-line me-1" />Publicação</h5></CardHeader>
                                    <CardBody>
                                        {isNew ? (
                                            <p className="text-muted mb-0">Guarde o artigo como rascunho. Depois pode enviá-lo para revisão.</p>
                                        ) : (
                                            <>
                                                <ul className="list-unstyled small mb-3">
                                                    <li className="mb-1"><span className="text-muted">Autor:</span> {blog?.author_name ?? ""}</li>
                                                    {blog?.submitted_at && <li className="mb-1"><span className="text-muted">Enviado para revisão:</span> {fmtDateTime(blog.submitted_at)}{blog.submitted_by_name ? `, por ${blog.submitted_by_name}` : ""}</li>}
                                                    {blog?.approved_at && <li className="mb-1"><span className="text-muted">Aprovado:</span> {fmtDateTime(blog.approved_at)}{blog.approved_by_name ? `, por ${blog.approved_by_name}` : ""}</li>}
                                                    {status === "approved" && <li className="mb-1 text-info"><i className="ri-calendar-check-line me-1" />Publica em {fmtDateTime(blog?.published_at)}</li>}
                                                    {status === "published" && <li className="mb-1 text-success"><i className="ri-checkbox-circle-line me-1" />Publicado em {fmtDateTime(blog?.published_at)}</li>}
                                                    {blog?.read_time ? <li><span className="text-muted">Leitura:</span> {blog.read_time} min</li> : null}
                                                </ul>
                                                <div className="d-grid gap-2">
                                                    {perms?.can_submit && (
                                                        <button type="button" className="btn btn-warning" disabled={acting || saving} onClick={() => act((bid) => submitBlog(companyId, bid), "Artigo enviado para revisão.")}>
                                                            <i className="ri-eye-2-line me-1" />Enviar para revisão
                                                        </button>
                                                    )}
                                                    {perms?.can_approve && (
                                                        <button type="button" className="btn btn-success" disabled={acting || saving} onClick={() => { setPublishMode("now"); setPublishAt(toLocalInput(new Date(Date.now() + 24 * 3600 * 1000))); setApproveOpen(true); }}>
                                                            <i className="ri-check-double-line me-1" />Aprovar
                                                        </button>
                                                    )}
                                                    {perms?.can_request_changes && (
                                                        <button type="button" className="btn btn-soft-warning" disabled={acting || saving} onClick={() => setChangesOpen(true)}>
                                                            <i className="ri-chat-1-line me-1" />Pedir alterações
                                                        </button>
                                                    )}
                                                    {perms?.can_unpublish && (
                                                        <button type="button" className="btn btn-soft-secondary" disabled={acting || saving}
                                                            onClick={() => window.confirm(status === "published" ? "Retirar o artigo do site e devolvê-lo a rascunho?" : "Cancelar o agendamento e devolver a rascunho?")
                                                                && act((bid) => blogBackToDraft(companyId, bid), "Artigo devolvido a rascunho.")}>
                                                            <i className="ri-arrow-go-back-line me-1" />{status === "published" ? "Retirar do site" : "Cancelar agendamento"}
                                                        </button>
                                                    )}
                                                    {status === "in_review" && !perms?.is_approver && (
                                                        <p className="small text-muted mb-0">A aguardar a aprovação de um administrador da empresa.</p>
                                                    )}
                                                    {perms?.can_delete && (
                                                        <button type="button" className="btn btn-link text-danger btn-sm" onClick={onDelete}>
                                                            <i className="ri-delete-bin-line me-1" />Apagar artigo
                                                        </button>
                                                    )}
                                                </div>
                                            </>
                                        )}
                                    </CardBody>
                                </Card>

                                <BlogSeoPanel
                                    values={{ ...v, hasBanner: !!bannerUrl }}
                                    siteUrl={blog?.site_url ?? null}
                                    bannerUrl={bannerUrl}
                                    disabled={!canEdit}
                                    onChange={(field, value) => set(field, value)}
                                />
                            </Col>
                        </Row>
                    </FormikProvider>
                </Container>
            </div>

            <Modal isOpen={approveOpen} toggle={() => setApproveOpen(false)} centered>
                <ModalHeader toggle={() => setApproveOpen(false)}>Aprovar artigo</ModalHeader>
                <ModalBody>
                    {markers && <div className="alert alert-danger small">Há "[VERIFICAR]" por resolver. A aprovação vai ser recusada até esses pontos serem confirmados.</div>}
                    <div className="form-check mb-2">
                        <Input type="radio" className="form-check-input" id="pub-now" checked={publishMode === "now"} onChange={() => setPublishMode("now")} />
                        <Label className="form-check-label" for="pub-now">Publicar agora</Label>
                    </div>
                    <div className="form-check mb-2">
                        <Input type="radio" className="form-check-input" id="pub-schedule" checked={publishMode === "schedule"} onChange={() => setPublishMode("schedule")} />
                        <Label className="form-check-label" for="pub-schedule">Agendar</Label>
                    </div>
                    {publishMode === "schedule" && (
                        <Input type="datetime-local" value={publishAt} min={toLocalInput(new Date())} onChange={(e) => setPublishAt(e.target.value)} />
                    )}
                    <p className="small text-muted mt-2 mb-0">Os artigos agendados são publicados automaticamente (verificação a cada 5 minutos).</p>
                </ModalBody>
                <ModalFooter>
                    <button type="button" className="btn btn-light" onClick={() => setApproveOpen(false)}>Cancelar</button>
                    <button type="button" className="btn btn-success" disabled={acting} onClick={onApprove}>
                        {acting && <Spinner size="sm" className="me-1" />}{publishMode === "now" ? "Aprovar e publicar" : "Aprovar e agendar"}
                    </button>
                </ModalFooter>
            </Modal>

            <Modal isOpen={changesOpen} toggle={() => setChangesOpen(false)} centered>
                <ModalHeader toggle={() => setChangesOpen(false)}>Pedir alterações</ModalHeader>
                <ModalBody>
                    <Label>O que deve ser alterado?</Label>
                    <textarea className="form-control" rows={4} maxLength={2000} value={changesNote} onChange={(e) => setChangesNote(e.target.value)} />
                    <p className="small text-muted mt-2 mb-0">O artigo volta a rascunho e a nota fica visível para quem o escreveu.</p>
                </ModalBody>
                <ModalFooter>
                    <button type="button" className="btn btn-light" onClick={() => setChangesOpen(false)}>Cancelar</button>
                    <button type="button" className="btn btn-warning" disabled={acting} onClick={onRequestChanges}>Devolver</button>
                </ModalFooter>
            </Modal>

            <BlogAiModal
                isOpen={aiOpen}
                toggle={() => setAiOpen(false)}
                companyId={companyId}
                blogId={blog?.id ?? null}
                defaultKeyword={v.focus_keyword}
                onApply={applyAi}
                onOpenBrandProfile={() => setProfileOpen(true)}
            />
            <BrandProfileModal isOpen={profileOpen} toggle={() => setProfileOpen(false)} companyId={companyId} />
        </React.Fragment>
    );
};

export default BlogEditor;
