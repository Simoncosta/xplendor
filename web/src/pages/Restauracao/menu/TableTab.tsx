import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Col, Input, Label, Row } from "reactstrap";
import { toast } from "react-toastify";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import XSelect from "Components/Common/Select";
import { downloadGet } from "helpers/download_helper";
import { MenuParams, getMenuEngineeringItems, menuEngineeringCsvPath } from "helpers/laravel_helper";
import { MenuItem } from "common/models/menuEngineering.model";
import { CLASS_META, CLASS_ORDER, CONFIDENCE_META, costLabel, fmtEur, fmtPct, fmtQty } from "./menuFormat";

/**
 * XPLENDOR — Fatia 3: a tabela da engenharia de menu. Uma página de cada vez; a ordenação e os filtros
 * são pedidos ao servidor (ListSort). Exporta CSV com os mesmos filtros e a mesma ordem.
 */
type Sort = { id: string; key: string; desc: boolean } | null;
const PER_PAGE = 50;

export default function TableTab({ companyId, params, families, hidden, onOpen, refreshKey }: {
    companyId: number; params: MenuParams; families: string[]; hidden: boolean; onOpen: (i: MenuItem) => void; refreshKey: number;
}) {
    const [rows, setRows] = useState<MenuItem[]>([]);
    const [total, setTotal] = useState(0);
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [sort, setSort] = useState<Sort>(null);
    const [family, setFamily] = useState("");
    const [klass, setKlass] = useState("");
    const [confidence, setConfidence] = useState("");
    const [search, setSearch] = useState("");
    const [excluded, setExcluded] = useState(false);

    const query = useCallback(() => ({
        ...params, family: family || undefined, class: klass || undefined, confidence: confidence || undefined, search: search.trim() || undefined,
        excluded: excluded ? "all" : "0", sort: sort?.key, dir: sort ? (sort.desc ? "desc" as const : "asc" as const) : undefined,
    }), [params, family, klass, confidence, search, excluded, sort]);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res: any = await getMenuEngineeringItems(companyId, { ...query(), page, perPage: PER_PAGE });
            setRows(res?.data?.items ?? []);
            setTotal(res?.data?.total ?? 0);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar a tabela.");
        } finally {
            setLoading(false);
        }
    }, [companyId, query, page]);
    useEffect(() => { const t = setTimeout(load, 200); return () => clearTimeout(t); }, [load, refreshKey]);
    useEffect(() => { setPage(1); }, [family, klass, confidence, search, excluded, sort, params]);

    const csv = () => {
        const q = Object.entries(query()).filter(([, v]) => v !== undefined && v !== "").map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`).join("&");
        downloadGet(menuEngineeringCsvPath(companyId, q), "engenharia-de-menu.csv");
    };

    const euro = (k: string) => (hidden ? undefined : k);
    const columns: DTColumn<MenuItem>[] = [
        { id: "description", header: "Prato", sortKey: "description", value: (r) => r.description, mobile: "title", hideable: false,
            cell: (r) => (
                <div style={{ minWidth: 170 }}>
                    <span className="fw-medium">{r.description}</span>
                    {r.code && <span className="text-muted fs-12 ms-1">{r.code}</span>}
                    <div className="text-muted fs-12">{r.family}</div>
                    {r.changed && <div className="fs-11 text-info">Ficha alterada no período</div>}
                    {r.excluded_reason && <div className="fs-11 text-muted">Fora da matriz: {r.excluded_reason}</div>}
                </div>
            ) },
        { id: "family", header: "Família", sortKey: "family", value: (r) => r.family, defaultVisible: false, cell: (r) => <span className="fs-13">{r.family}</span> },
        { id: "qty", header: "Quantidade", sortKey: "qty", value: (r) => r.qty, align: "end", nowrap: true, cell: (r) => fmtQty(r.qty) },
        { id: "share", header: "Quota", sortKey: "share", value: (r) => r.share_pct, align: "end", nowrap: true, cell: (r) => fmtPct(r.share_pct) },
        { id: "price", header: "Preço sem IVA", sortKey: euro("price"), value: (r) => r.price_eur, align: "end", nowrap: true, cell: (r) => fmtEur(r.price_eur) },
        { id: "cost", header: "Custo", sortKey: euro("cost"), value: (r) => r.cost_eur, align: "end", nowrap: true, cell: (r) => costLabel(r) },
        { id: "margin", header: "Margem unitária", sortKey: euro("margin"), value: (r) => r.margin_eur, align: "end", nowrap: true, cell: (r) => fmtEur(r.margin_eur) },
        { id: "margin_total", header: "Margem total", sortKey: euro("margin_total"), value: (r) => r.margin_total_eur, align: "end", nowrap: true, cell: (r) => fmtEur(r.margin_total_eur, 0) },
        { id: "food_cost", header: "Food cost", sortKey: "food_cost", value: (r) => r.food_cost_pct, align: "end", nowrap: true, cell: (r) => (r.food_cost_pct === null ? "—" : r.confidence === "parcial" ? `≥ ${fmtPct(r.food_cost_pct)}` : fmtPct(r.food_cost_pct)) },
        { id: "class", header: "Classe", sortKey: "class", value: (r) => r.class, nowrap: true,
            cell: (r) => r.class ? (
                <span><Badge color={`${CLASS_META[r.class].color}-subtle`} className={`text-${CLASS_META[r.class].color} fs-11`}><i className={`${CLASS_META[r.class].icon} me-1`} />{CLASS_META[r.class].label}</Badge>
                    {r.fragile && <i className="ri-error-warning-line text-warning ms-1" title={r.fragile_reasons.map((f) => f.text).join(" ")} />}</span>
            ) : <span className="text-muted">—</span> },
        { id: "confidence", header: "Confiança", sortKey: "confidence", value: (r) => r.confidence, nowrap: true,
            cell: (r) => <Badge color={`${CONFIDENCE_META[r.confidence].color}-subtle`} className={`text-${CONFIDENCE_META[r.confidence].color} fs-11`} title={CONFIDENCE_META[r.confidence].text}>{CONFIDENCE_META[r.confidence].label}</Badge> },
        { id: "play", header: "Jogada", sortable: false, value: (r) => r.play, defaultVisible: false, cell: (r) => <span className="fs-12">{r.play ?? "—"}</span> },
    ];
    const cols = useDataColumns("restauracao.menu", columns);
    const lastPage = Math.max(1, Math.ceil(total / PER_PAGE));

    return (
        <div data-testid="menu-table">
            <Row className="g-2 align-items-end mb-2">
                <Col xs={12} md={3}><Input bsSize="sm" placeholder="Pesquisar artigo ou código" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Pesquisar" /></Col>
                <Col xs={6} md={3}><XSelect<string> value={family} ariaLabel="Família" small options={[{ value: "", label: "Todas as famílias" }, ...families.map((f) => ({ value: f, label: f }))]} onChange={setFamily} /></Col>
                <Col xs={6} md={2}><XSelect<string> value={klass} ariaLabel="Classe" small options={[{ value: "", label: "Todas as classes" }, ...CLASS_ORDER.map((c) => ({ value: c, label: CLASS_META[c].label })), { value: "sem_custo", label: "Sem custo" }]} onChange={setKlass} /></Col>
                <Col xs={6} md={2}><XSelect<string> value={confidence} ariaLabel="Confiança" small options={[{ value: "", label: "Toda a confiança" }, ...(["confirmado", "parcial", "sem_custo"] as const).map((c) => ({ value: c, label: CONFIDENCE_META[c].label }))]} onChange={setConfidence} /></Col>
                <Col xs={6} md={2} className="d-flex align-items-center justify-content-end gap-2">
                    <div className="form-check form-switch mb-0">
                        <Input type="switch" className="form-check-input" id="me-excluded" checked={excluded} onChange={(e) => setExcluded(e.target.checked)} />
                        <Label className="form-check-label fs-12" for="me-excluded">Excluídos</Label>
                    </div>
                    <Button size="sm" color="light" onClick={csv} title="Exportar CSV"><i className="ri-download-2-line" /></Button>
                </Col>
            </Row>
            <DataTable
                columns={cols}
                data={rows}
                rowKey={(r) => r.product_id}
                mode="server"
                loading={loading}
                onRowClick={onOpen}
                caption="Engenharia de menu: artigos"
                empty={{ message: "Nenhum artigo para estes filtros." }}
                server={{
                    page, lastPage, total, perPage: PER_PAGE, from: total ? (page - 1) * PER_PAGE + 1 : 0, to: Math.min(total, page * PER_PAGE), onPageChange: setPage,
                    sort: sort ? { id: sort.id, desc: sort.desc } : null, onSortChange: (next) => setSort(next),
                }}
            />
        </div>
    );
}
