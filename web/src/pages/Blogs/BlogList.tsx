import React, { useCallback, useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Badge, Card, CardBody, Col, Container, Input, Row, Spinner } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import Pagination from "Components/Common/Pagination";
import PageHeader from "Components/Common/PageHeader";
import { getBlogs, getBrandProfile } from "helpers/laravel_helper";
import { BLOG_STATUS_META, BLOG_STATUS_ORDER, BlogStatus, IBlogListItem, blogImage, fmtDateTime } from "common/models/blog.model";
import { getWorkingCompanyId } from "helpers/workingCompany";

/**
 * Artigos do blog da empresa. Os que estão em revisão aparecem primeiro; o administrador
 * vê o aviso de quantos aguardam a sua aprovação. "Perfil da marca" alimenta a IA.
 */
const BlogList = () => {
    document.title = "Blog | Xplendor";
    const navigate = useNavigate();
    const companyId = getWorkingCompanyId();

    const [items, setItems] = useState<IBlogListItem[]>([]);
    const [meta, setMeta] = useState<any>(null);
    const [counts, setCounts] = useState<Record<string, number>>({});
    const [canApprove, setCanApprove] = useState(false);
    const [status, setStatus] = useState<BlogStatus | "">("");
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    // Perfil da marca (página própria): só para o aviso de perfil por preencher.
    const [profileEmpty, setProfileEmpty] = useState(false);

    useEffect(() => {
        if (!companyId) return;
        getBrandProfile(companyId).then((r: any) => setProfileEmpty(!!r?.data?.is_empty)).catch(() => setProfileEmpty(false));
    }, [companyId]);

    const load = useCallback(() => {
        if (!companyId) return;
        setLoading(true);
        getBlogs(companyId, { status: status || undefined, search: search.trim() || undefined, perPage: 20, page })
            .then((r: any) => {
                setItems(r.data.page.data ?? []);
                setMeta(r.data.page);
                setCounts(r.data.counts ?? {});
                setCanApprove(!!r.data.can_approve);
            })
            .catch(() => toast.error("Não foi possível carregar os artigos."))
            .finally(() => setLoading(false));
    }, [companyId, status, search, page]);

    useEffect(() => {
        const t = setTimeout(load, search ? 300 : 0);
        return () => clearTimeout(t);
    }, [load, search]);

    const total = Object.values(counts).reduce((a, b) => a + Number(b), 0);
    const inReview = Number(counts.in_review ?? 0);

    return (
        <React.Fragment>
            <ToastContainer />
            <div className="page-content">
                <Container fluid>
                    <PageHeader title="Blogs" breadcrumbs={[{ label: "Marketing" }]}
                        description="Artigos do site: rascunho, revisão, aprovação e publicação."
                        actions={<>
                            <Link to="/brand-profile" className="btn btn-outline-primary"><i className="ri-user-voice-line me-1" />Perfil da Marca</Link>
                            <Link to="/blogs/create" className="btn btn-primary"><i className="ri-add-line me-1" />Novo artigo</Link>
                        </>} />

                    {profileEmpty && (
                        <div className="alert alert-info d-flex flex-wrap align-items-center gap-2" role="alert">
                            <i className="ri-user-voice-line fs-5" />
                            <div className="flex-grow-1">
                                O perfil da marca ainda não está preenchido. Com ele, os rascunhos feitos com IA seguem o tom e o público da marca.
                            </div>
                            <Link to="/brand-profile" className="btn btn-sm btn-outline-primary">Preencher o Perfil da Marca</Link>
                        </div>
                    )}

                    {canApprove && inReview > 0 && (
                        <div className="alert alert-warning d-flex align-items-center gap-2" role="alert">
                            <i className="ri-eye-2-line fs-5" />
                            <div className="flex-grow-1">
                                {inReview === 1 ? "Há 1 artigo à espera da sua aprovação." : `Há ${inReview} artigos à espera da sua aprovação.`}
                            </div>
                            <button type="button" className="btn btn-sm btn-outline-primary" onClick={() => { setStatus("in_review"); setPage(1); }}>Ver</button>
                        </div>
                    )}

                    <Card>
                        <CardBody>
                            <Row className="g-2 mb-3 align-items-center">
                                <Col md="auto">
                                    <div className="xp-seg" role="tablist" aria-label="Estado">
                                        <button type="button" role="tab" aria-selected={status === ""} className={status === "" ? "on" : ""} onClick={() => { setStatus(""); setPage(1); }}>
                                            Todos <span className="ms-1 opacity-75">{total}</span>
                                        </button>
                                        {BLOG_STATUS_ORDER.map((s) => (
                                            <button key={s} type="button" role="tab" aria-selected={status === s} className={status === s ? "on" : ""} onClick={() => { setStatus(s); setPage(1); }}>
                                                {BLOG_STATUS_META[s].label} <span className="ms-1 opacity-75">{Number(counts[s] ?? 0)}</span>
                                            </button>
                                        ))}
                                    </div>
                                </Col>
                                <Col md={4} className="ms-md-auto">
                                    <div className="search-box">
                                        <Input value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} placeholder="Procurar pelo título" />
                                        <i className="ri-search-line search-icon" />
                                    </div>
                                </Col>
                            </Row>

                            {loading ? (
                                <div className="text-center py-5"><Spinner size="sm" /></div>
                            ) : items.length === 0 ? (
                                <div className="text-center text-muted py-5">
                                    <i className="ri-article-line fs-1 d-block mb-2" />
                                    {total === 0 ? "Ainda não há artigos. Comece por \"Novo artigo\"." : "Nenhum artigo com estes filtros."}
                                </div>
                            ) : (
                                <div className="table-responsive">
                                    <table className="table table-hover align-middle mb-0">
                                        <thead className="table-light">
                                            <tr>
                                                <th style={{ width: 72 }}></th>
                                                <th>Artigo</th>
                                                <th>Estado</th>
                                                <th>Publicação</th>
                                                <th>Autor</th>
                                                <th className="text-end">Atualizado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {items.map((b) => {
                                                const sm = BLOG_STATUS_META[b.status];
                                                const img = blogImage(b.banner);
                                                return (
                                                    <tr key={b.id} role="button" onClick={() => navigate(`/blogs/${b.id}`)}>
                                                        <td>
                                                            {img
                                                                ? <img src={img} alt="" className="rounded object-fit-cover" style={{ width: 56, height: 36 }} />
                                                                : <div className="rounded bg-light d-flex align-items-center justify-content-center text-muted" style={{ width: 56, height: 36 }}><i className="ri-image-line" /></div>}
                                                        </td>
                                                        <td>
                                                            <div className="fw-medium">{b.title}</div>
                                                            <div className="text-muted small">/{b.slug}{b.focus_keyword ? ` · ${b.focus_keyword}` : ""}</div>
                                                        </td>
                                                        <td>
                                                            <Badge color={`${sm.color}-subtle`} className={`text-${sm.color}`}><i className={`${sm.icon} me-1`} />{sm.label}</Badge>
                                                            {b.has_review_note && b.status === "draft" && <div className="small text-warning mt-1"><i className="ri-chat-1-line me-1" />Alterações pedidas</div>}
                                                        </td>
                                                        <td className="small">{b.published_at ? fmtDateTime(b.published_at) : <span className="text-muted">Sem data</span>}</td>
                                                        <td className="small">{b.author_name ?? ""}</td>
                                                        <td className="small text-end text-muted">{fmtDateTime(b.updated_at)}</td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            {meta && meta.last_page > 1 && (
                                <Pagination
                                    currentPage={meta.current_page}
                                    lastPage={meta.last_page}
                                    total={meta.total}
                                    perPage={meta.per_page}
                                    from={meta.from ?? 0}
                                    to={meta.to ?? 0}
                                    onPageChange={(p) => setPage(p)}
                                />
                            )}
                        </CardBody>
                    </Card>
                </Container>
            </div>
        </React.Fragment>
    );
};

export default BlogList;
