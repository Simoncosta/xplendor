import { ReactNode, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import { getArticleVatDivergences } from "helpers/laravel_helper";
import { ArticleVatDivergence, ArticleVatReport } from "common/models/ocr.model";

/**
 * XPLENDOR — F2d: "IVA a rever" (só leitura), para limpar o catálogo. Artigos cujo IVA faturado
 * pelos fornecedores (linhas reais dos documentos do PingWin) é diferente do IVA do artigo:
 * quantas linhas, com que taxas, de que fornecedores e quando foi a última. A linha abre o artigo.
 */
const fmtDay = (d?: string | null) => (d ? d.split("-").reverse().join("/") : "—");

export default function ArtigosVatReport({ companyId, viewToggle }: { companyId: number | null; viewToggle: ReactNode }) {
    const navigate = useNavigate();
    const [data, setData] = useState<ArticleVatReport | null>(null);
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState("");

    useEffect(() => {
        if (!companyId) return;
        let alive = true;
        setLoading(true);
        getArticleVatDivergences(companyId)
            .then((r: any) => { if (alive) setData(r?.data ?? null); })
            .catch(() => { if (alive) setData(null); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId]);

    const columns: DTColumn<ArticleVatDivergence>[] = [
        { id: "code", header: "Código", value: (a) => a.code, cell: (a) => <span className="fw-medium">{a.code ?? "—"}</span>, mobile: "subtitle" },
        {
            id: "description", header: "Artigo", value: (a) => `${a.description ?? ""} ${a.code ?? ""}`, mobile: "title",
            cell: (a) => <>{a.description ?? "—"}{!a.is_active && <span className="badge bg-secondary-subtle text-secondary ms-2">Inativo</span>}</>,
        },
        { id: "article_rate", header: "IVA do artigo", value: (a) => a.article_rate, cell: (a) => `${a.article_rate}%`, align: "end", nowrap: true },
        {
            id: "invoiced", header: "IVA faturado", value: (a) => a.invoiced_rates[0]?.rate ?? null, nowrap: true,
            cell: (a) => a.invoiced_rates.map((r) => <span key={r.rate} className="badge bg-warning-subtle text-warning me-1">{r.rate}% × {r.lines}</span>),
        },
        { id: "lines", header: "Linhas", value: (a) => a.divergent_lines, cell: (a) => <>{a.divergent_lines} <span className="text-muted">de {a.total_lines}</span></>, align: "end", nowrap: true },
        { id: "suppliers", header: "Fornecedores", value: (a) => a.suppliers.map((s) => s.name).join(", "), cell: (a) => a.suppliers.map((s) => s.name).join(", ") || "—" },
        { id: "last", header: "Última", value: (a) => a.last_date, cell: (a) => fmtDay(a.last_date), nowrap: true },
    ];
    const cols = useDataColumns("restauracao.artigos.iva", columns);
    const s = data?.summary;

    return (
        <PageCard
            title="IVA a rever"
            loading={loading && !!data}
            status={s ? <>{s.articles_divergent} de {s.articles_checked} artigos comprados com IVA diferente do catálogo ({s.lines_divergent} linhas)</> : undefined}
            info="Compara o IVA das linhas das faturas de fornecedor no PingWin com o IVA do artigo no catálogo. Só leitura: para corrigir, abra o artigo."
            actions={<>{viewToggle}{cols.selector}</>}
            filters={<RestFilterBar search={search} onSearchChange={setSearch} searchPlaceholder="Pesquisar (código, artigo ou fornecedor)…" activeCount={search ? 1 : 0} onClear={() => setSearch("")} />}
            flush
        >
            <DataTable
                columns={cols}
                data={data?.articles ?? []}
                rowKey={(a) => a.article_id}
                loading={loading}
                search={search}
                pageSize={20}
                initialSort={{ id: "lines", desc: true }}
                caption="IVA a rever"
                onRowClick={(a) => a.pingwin_id && navigate(`/restauracao/artigos/${a.pingwin_id}`, { state: { catalogItemId: a.article_id } })}
                empty={{ message: "Nenhum artigo com IVA faturado diferente do catálogo." }}
            />
        </PageCard>
    );
}
