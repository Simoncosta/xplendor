import React, { useCallback, useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Badge, Container } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import { fetchAllPages } from "helpers/fetchAllPages";
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
    const [counts, setCounts] = useState<Record<string, number>>({});
    const [canApprove, setCanApprove] = useState(false);
    const [status, setStatus] = useState<BlogStatus | "">("");
    const [search, setSearch] = useState("");
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
        // UI-2b: todas as páginas (a API filtra e pesquisa, mas só tem a sua ordem); o DataTable
        // ordena e pagina no browser, a partir da ordem da API (em revisão primeiro).
        fetchAllPages<IBlogListItem>(
            (page) => getBlogs(companyId, { status: status || undefined, search: search.trim() || undefined, perPage: 100, page }),
            (r: any) => r?.data?.page,
        )
            .then(({ rows, first }: any) => {
                setItems(rows);
                setCounts(first?.data?.counts ?? {});
                setCanApprove(!!first?.data?.can_approve);
            })
            .catch(() => toast.error("Não foi possível carregar os artigos."))
            .finally(() => setLoading(false));
    }, [companyId, status, search]);

    useEffect(() => {
        const t = setTimeout(load, search ? 300 : 0);
        return () => clearTimeout(t);
    }, [load, search]);

    const total = Object.values(counts).reduce((a, b) => a + Number(b), 0);
    const inReview = Number(counts.in_review ?? 0);

    const cols = useDataColumns<IBlogListItem>("marketing.blogs", [
        {
            id: "image", header: "Imagem", mobile: "hide", className: "", nowrap: true,
            cell: (b) => {
                const img = blogImage(b.banner);
                return img
                    ? <img src={img} alt="" className="rounded object-fit-cover" style={{ width: 56, height: 36 }} />
                    : <div className="rounded bg-light d-flex align-items-center justify-content-center text-muted" style={{ width: 56, height: 36 }}><i className="ri-image-line" /></div>;
            },
        },
        {
            id: "title", header: "Artigo", value: (b) => b.title, hideable: false, mobile: "title",
            cell: (b) => (
                <div>
                    <div className="fw-medium">{b.title}</div>
                    <div className="text-muted small">/{b.slug}{b.focus_keyword ? ` · ${b.focus_keyword}` : ""}</div>
                </div>
            ),
        },
        {
            id: "status", header: "Estado", value: (b) => BLOG_STATUS_ORDER.indexOf(b.status),
            cell: (b) => {
                const sm = BLOG_STATUS_META[b.status];
                return (
                    <>
                        <Badge color={`${sm.color}-subtle`} className={`text-${sm.color}`}><i className={`${sm.icon} me-1`} />{sm.label}</Badge>
                        {b.has_review_note && b.status === "draft" && <div className="small text-warning mt-1"><i className="ri-chat-1-line me-1" />Alterações pedidas</div>}
                    </>
                );
            },
        },
        {
            id: "published", header: "Publicação", value: (b) => b.published_at ?? undefined,
            cell: (b) => <span className="small">{b.published_at ? fmtDateTime(b.published_at) : <span className="text-muted">Sem data</span>}</span>,
        },
        { id: "author", header: "Autor", value: (b) => b.author_name ?? "", cell: (b) => <span className="small">{b.author_name ?? ""}</span> },
        { id: "updated", header: "Atualizado", value: (b) => b.updated_at, cell: (b) => <span className="small text-muted">{fmtDateTime(b.updated_at)}</span>, align: "end", nowrap: true },
    ] as DTColumn<IBlogListItem>[]);

    const statusTabs = (
        <div className="xp-seg" role="tablist" aria-label="Estado">
            <button type="button" role="tab" aria-selected={status === ""} className={status === "" ? "on" : ""} onClick={() => setStatus("")}>
                Todos <span className="ms-1 opacity-75">{total}</span>
            </button>
            {BLOG_STATUS_ORDER.map((s) => (
                <button key={s} type="button" role="tab" aria-selected={status === s} className={status === s ? "on" : ""} onClick={() => setStatus(s)}>
                    {BLOG_STATUS_META[s].label} <span className="ms-1 opacity-75">{Number(counts[s] ?? 0)}</span>
                </button>
            ))}
        </div>
    );

    return (
        <React.Fragment>
            <ToastContainer />
            <div className="page-content">
                <Container fluid>
                    <PageHeader title="Blogs" breadcrumbs={[{ label: "Marketing" }]}
                        info="Os artigos do site: rascunho, revisão, aprovação e publicação." />

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
                            <button type="button" className="btn btn-sm btn-outline-primary" onClick={() => setStatus("in_review")}>Ver</button>
                        </div>
                    )}

                    <PageCard
                        title="Artigos"
                        status={<>{items.length} artigo{items.length === 1 ? "" : "s"}{status ? ` · ${BLOG_STATUS_META[status].label}` : ""}</>}
                        loading={loading && items.length > 0}
                        actions={<>
                            {cols.selector}
                            <Link to="/brand-profile" className="btn btn-outline-primary btn-sm"><i className="ri-user-voice-line me-1" />Perfil da Marca</Link>
                            <Link to="/blogs/create" className="btn btn-primary btn-sm"><i className="ri-add-line me-1" />Novo artigo</Link>
                        </>}
                        filters={
                            <RestFilterBar search={search} onSearchChange={setSearch} searchPlaceholder="Procurar pelo título"
                                activeCount={(status ? 1 : 0) + (search ? 1 : 0)} onClear={() => { setStatus(""); setSearch(""); }}>
                                <div style={{ flex: "0 1 auto", minWidth: 0 }}>{statusTabs}</div>
                            </RestFilterBar>
                        }
                    >
                        <DataTable
                            columns={cols}
                            data={items}
                            rowKey={(b) => b.id}
                            mode="client"
                            loading={loading}
                            pageSize={20}
                            caption="Artigos do blog"
                            onRowClick={(b) => navigate(`/blogs/${b.id}`)}
                            empty={{
                                message: total === 0 ? <>Ainda não há artigos. Comece por "Novo artigo".</> : "Nenhum artigo com estes filtros.",
                                action: total === 0 ? <Link to="/blogs/create" className="btn btn-outline-primary btn-sm"><i className="ri-add-line me-1" />Novo artigo</Link> : undefined,
                            }}
                            rowActions={(b) => (
                                <Link to={`/blogs/${b.id}`} className="btn btn-outline-primary btn-sm" title="Abrir" aria-label={`Abrir: ${b.title}`} onClick={(e) => e.stopPropagation()}>
                                    <i className="ri-pencil-line" />
                                </Link>
                            )}
                        />
                    </PageCard>
                </Container>
            </div>
        </React.Fragment>
    );
};

export default BlogList;
