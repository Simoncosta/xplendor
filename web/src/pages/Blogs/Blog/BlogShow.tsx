import React, { useEffect, useMemo, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { Badge, Container, Spinner } from "reactstrap";
import { showBlog } from "helpers/laravel_helper";
import { BLOG_STATUS_META, IBlogPost, blogImage, fmtDateTime } from "common/models/blog.model";

/**
 * Pré-visualização de leitura do artigo. O conteúdo chega já limpo pelo servidor (lista de
 * etiquetas permitidas), também nos registos antigos.
 */
const readAuth = () => {
    try { return JSON.parse(sessionStorage.getItem("authUser") || "null") ?? {}; } catch { return {}; }
};

export default function BlogShow() {
    const { id } = useParams();
    const auth = useMemo(readAuth, []);
    const companyId = Number(auth.company_id || 0);
    const [blog, setBlog] = useState<IBlogPost | null>(null);
    const [loading, setLoading] = useState(true);

    document.title = `${blog?.title ?? "Artigo"} | Xplendor`;

    useEffect(() => {
        if (!companyId) return;
        showBlog(companyId, Number(id)).then((r: any) => setBlog(r.data)).catch(() => setBlog(null)).finally(() => setLoading(false));
    }, [companyId, id]);

    if (loading) return <div className="page-content"><Container fluid><div className="text-center py-5"><Spinner /></div></Container></div>;
    if (!blog) return <div className="page-content"><Container fluid><p className="text-muted">Artigo não encontrado.</p></Container></div>;

    const sm = BLOG_STATUS_META[blog.status];
    const img = blogImage(blog.banner);

    return (
        <div className="page-content">
            <Container fluid>
                <div className="row justify-content-center">
                    <div className="col-xxl-9">
                        <div className="d-flex justify-content-between align-items-center mb-3">
                            <Link to={`/blogs/${blog.id}`} className="btn btn-sm btn-light"><i className="ri-arrow-left-line me-1" />Voltar ao editor</Link>
                            <Badge color={`${sm.color}-subtle`} className={`text-${sm.color}`}>{sm.label}</Badge>
                        </div>
                        <div className="card">
                            <div className="card-body">
                                <div className="text-center mb-4">
                                    {blog.category && <p className="text-success text-uppercase mb-2">{blog.category}</p>}
                                    <h2 className="mb-2">{blog.title}</h2>
                                    {blog.subtitle && <p className="fs-16 mb-2">{blog.subtitle}</p>}
                                    <p className="text-muted mb-3">{blog.published_at ? fmtDateTime(blog.published_at) : "Sem data de publicação"}{blog.read_time ? ` · ${blog.read_time} min de leitura` : ""}</p>
                                    <div className="d-flex align-items-center justify-content-center flex-wrap gap-2">
                                        {(blog.tags ?? []).map((tag) => <span key={tag} className="badge bg-primary-subtle text-primary">{tag}</span>)}
                                    </div>
                                </div>
                                {img && <img src={img} alt="" className="img-fluid rounded mb-4 w-100" />}
                                <div className="blog-content fs-15" dangerouslySetInnerHTML={{ __html: blog.content ?? "" }} />
                            </div>
                        </div>
                    </div>
                </div>
            </Container>
        </div>
    );
}
