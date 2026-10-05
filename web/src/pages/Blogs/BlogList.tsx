import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Badge, Card, CardBody, Col, Container, Input, Row, Spinner } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import Pagination from "Components/Common/Pagination";
import { getBlogs } from "helpers/laravel_helper";
import { BLOG_STATUS_META, BLOG_STATUS_ORDER, BlogStatus, IBlogListItem, blogImage, fmtDateTime } from "common/models/blog.model";
import BrandProfileModal from "./BrandProfileModal";

/**
 * Artigos do blog da empresa. Os que estão em revisão aparecem primeiro; o administrador
 * vê o aviso de quantos aguardam a sua aprovação. "Perfil da marca" alimenta a IA.
 */
const readAuth = () => {
    try { return JSON.parse(sessionStorage.getItem("authUser") || "null") ?? {}; } catch { return {}; }
};

const BlogList = () => {
    document.title = "Blog | Xplendor";
    const navigate = useNavigate();
    const auth = useMemo(readAuth, []);
    const companyId = Number(auth.company_id || 0);

    const [items, setItems] = useState<IBlogListItem[]>([]);
    const [meta, setMeta] = useState<any>(null);
    const [counts, setCounts] = useState<Record<string, number>>({});
    const [canApprove, setCanApprove] = useState(false);
    const [status, setStatus] = useState<BlogStatus | "">("");
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [profileOpen, setProfileOpen] = useState(false);

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
                    <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <div>
                            <h4 className="mb-1">Blog</h4>
                            <p className="text-muted mb-0">Artigos do site: rascunho, revisão, aprovação e publicação.</p>
                        </div>
                        <div className="d-flex gap-2">
                            <button type="button" className="btn btn-soft-secondary" onClick={() => setProfileOpen(true)}>
                                <i className="ri-user-voice-line me-1" />Perfil da marca
                            </button>
                            <Link to="/blogs/create" className="btn btn-success">
                                <i className="ri-add-line me-1" />Novo artigo
                            </Link>
                        </div>
                    </div>

                    {canApprove && inReview > 0 && (
                        <div className="alert alert-warning d-flex align-items-center gap-2" role="alert">
                            <i className="ri-eye-2-line fs-5" />
                            <div className="flex-grow-1">
                                {inReview === 1 ? "Há 1 artigo à espera da sua aprovação." : `Há ${inReview} artigos à espera da sua aprovação.`}
                            </div>
                            <button type="button" className="btn btn-sm btn-warning" onClick={() => { setStatus("in_review"); setPage(1); }}>Ver</button>
                        </div>
                    )}

                    <Card>
                        <CardBody>
                            <Row className="g-2 mb-3 align-items-center">
                                <Col md="auto">
                                    <div className="d-flex flex-wrap gap-1">
                                        <button type="button" className={`btn btn-sm ${status === "" ? "btn-primary" : "btn-light"}`} onClick={() => { setStatus(""); setPage(1); }}>
                                            Todos <span className="ms-1 opacity-75">{total}</span>
                                        </button>
                                        {BLOG_STATUS_ORDER.map((s) => (
                                            <button key={s} type="button" className={`btn btn-sm ${status === s ? "btn-primary" : "btn-light"}`} onClick={() => { setStatus(s); setPage(1); }}>
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
            <BrandProfileModal isOpen={profileOpen} toggle={() => setProfileOpen(false)} companyId={companyId} />
        </React.Fragment>
    );
};

export default BlogList;
