import { useEffect, useMemo, useState } from "react";
import { Card, CardBody, Col, Container, Row } from "reactstrap";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import RestFilterBar from "Components/Common/RestFilterBar";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import { getAdminStorageUsage } from "helpers/laravel_helper";

/**
 * Espaço por empresa (só o root): o que cada empresa tem guardado, por tipo (Linha Editorial
 * com as variantes, faturas do OCR, cobranças e comprovativos, faturas dos pedidos de suporte
 * e fotografias dos relatórios de satisfação) e no total, em MB ou GB. A soma vem da base de
 * dados (o tamanho de cada ficheiro), sem listar o disco nem o bucket.
 */

type Kind = "media" | "ocr" | "cobrancas" | "faturas_tickets" | "fotos_relatorios";
type UsageRow = { company_id: number; company: string; bytes: Record<Kind, number>; total: number; sem_tamanho: number };
type Payload = { kinds: Record<Kind, string>; rows: UsageRow[]; totals: { bytes: Record<Kind, number>; total: number; sem_tamanho: number }; quota_bytes: number };

const KIND_ORDER: Kind[] = ["media", "ocr", "cobrancas", "faturas_tickets", "fotos_relatorios"];

/** MB até 1 GB; GB a partir daí. */
export const fmtBytes = (b: number): string => {
    if (b >= 1024 ** 3) return `${(b / 1024 ** 3).toLocaleString("pt-PT", { maximumFractionDigits: 2 })} GB`;
    return `${(b / 1024 ** 2).toLocaleString("pt-PT", { maximumFractionDigits: 1, minimumFractionDigits: b > 0 && b < 1024 ** 2 ? 1 : 0 })} MB`;
};

export default function StorageUsagePage() {
    const [data, setData] = useState<Payload | null>(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState("");

    useEffect(() => {
        getAdminStorageUsage().then((r: any) => setData(r?.data ?? null)).catch(() => setData(null)).finally(() => setLoading(false));
    }, []);

    const defs = useMemo<DTColumn<UsageRow>[]>(() => [
        { id: "company", header: "Empresa", value: (r) => r.company, mobile: "title" },
        ...KIND_ORDER.map((k): DTColumn<UsageRow> => ({
            id: k, header: data?.kinds[k] ?? k, value: (r) => r.bytes[k], cell: (r) => fmtBytes(r.bytes[k]), align: "end", nowrap: true,
        })),
        { id: "total", header: "Total", value: (r) => r.total, cell: (r) => <span className="fw-semibold">{fmtBytes(r.total)}</span>, align: "end", nowrap: true },
        {
            id: "quota", header: "Da quota", value: (r) => (data?.quota_bytes ? r.total / data.quota_bytes : 0), align: "end", nowrap: true,
            cell: (r) => {
                if (!data?.quota_bytes) return "Sem quota";
                const pct = (r.total / data.quota_bytes) * 100;
                return pct > 0 && pct < 1 ? "menos de 1%" : `${Math.round(pct)}%`;
            },
        },
        {
            id: "missing", header: "Sem tamanho", value: (r) => r.sem_tamanho, align: "end", defaultVisible: false,
            cell: (r) => (r.sem_tamanho > 0 ? <span className="text-warning" title="Ficheiros anteriores à contagem: correr php artisan storage:fill-sizes">{r.sem_tamanho}</span> : "0"),
        },
    ], [data]);
    const cols = useDataColumns<UsageRow>("admin.espaco", defs);

    const quota = data?.quota_bytes ? fmtBytes(data.quota_bytes) : null;

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader title="Espaço por empresa" breadcrumbs={[{ label: "Administração" }]}
                    info={`O que cada empresa tem guardado, por tipo, somado a partir do tamanho de cada ficheiro registado na base de dados.${quota ? ` Quota por empresa: ${quota}.` : ""}`} />

                <Row className="g-3 mb-3">
                    <Col sm={6} xl={3}>
                        <Card className="mb-0 h-100"><CardBody>
                            <p className="text-uppercase fw-medium text-muted fs-12 mb-1">Total guardado</p>
                            <h4 className="fs-22 fw-semibold ff-secondary mb-0" data-testid="storage-total">{fmtBytes(data?.totals.total ?? 0)}</h4>
                        </CardBody></Card>
                    </Col>
                    <Col sm={6} xl={3}>
                        <Card className="mb-0 h-100"><CardBody>
                            <p className="text-uppercase fw-medium text-muted fs-12 mb-1">Linha Editorial</p>
                            <h4 className="fs-22 fw-semibold ff-secondary mb-0">{fmtBytes(data?.totals.bytes.media ?? 0)}</h4>
                        </CardBody></Card>
                    </Col>
                    <Col sm={6} xl={3}>
                        <Card className="mb-0 h-100"><CardBody>
                            <p className="text-uppercase fw-medium text-muted fs-12 mb-1">Faturas, cobranças e fotografias</p>
                            <h4 className="fs-22 fw-semibold ff-secondary mb-0">{fmtBytes((data?.totals.total ?? 0) - (data?.totals.bytes.media ?? 0))}</h4>
                        </CardBody></Card>
                    </Col>
                    <Col sm={6} xl={3}>
                        <Card className="mb-0 h-100"><CardBody>
                            <p className="text-uppercase fw-medium text-muted fs-12 mb-1">Ficheiros sem tamanho</p>
                            <h4 className={`fs-22 fw-semibold ff-secondary mb-0 ${(data?.totals.sem_tamanho ?? 0) > 0 ? "text-warning" : ""}`}>{data?.totals.sem_tamanho ?? 0}</h4>
                            {(data?.totals.sem_tamanho ?? 0) > 0 && <span className="text-muted fs-12">Anteriores à contagem: preenche-os o comando storage:fill-sizes.</span>}
                        </CardBody></Card>
                    </Col>
                </Row>

                <PageCard
                    title="Por empresa"
                    status={!loading && data ? <>{data.rows.length} empresa{data.rows.length === 1 ? "" : "s"} com ficheiros</> : undefined}
                    actions={cols.selector}
                    filters={<RestFilterBar search={search} onSearchChange={setSearch} searchPlaceholder="Pesquisar empresa…" activeCount={search ? 1 : 0} onClear={() => setSearch("")} />}
                >
                    <DataTable
                        columns={cols}
                        data={data?.rows ?? []}
                        rowKey={(r) => r.company_id}
                        loading={loading}
                        search={search}
                        pageSize={25}
                        caption="Espaço por empresa"
                        initialSort={{ id: "total", desc: true }}
                        empty={{ message: "Nenhuma empresa tem ficheiros guardados." }}
                    />
                </PageCard>
            </Container>
        </div>
    );
}
