import { useEffect, useState } from "react";
import { Card, CardBody, Row, Col, Spinner } from "reactstrap";
import { getEditorialSectors } from "helpers/laravel_helper";

/**
 * XPLENDOR — Linha Editorial: escolha do RAMO (setor-folha). Componente REUTILIZÁVEL
 * (aqui a 1.ª escolha; nas Configurações servirá para a troca — fase futura).
 * ⚠️ A escolha tem peso: define as datas editoriais do setor; a troca é destrutiva.
 */

type Sector = { id: number; name: string; slug: string };

const ICONS: Record<string, string> = {
    restauracao: "ri-restaurant-2-line",
    carros: "ri-car-line",
    autocaravanas: "ri-caravan-line",
    domotica: "ri-home-gear-line",
};

export default function SectorChooser({
    companyId,
    busy,
    onChoose,
}: {
    companyId: number;
    busy?: boolean;
    onChoose: (sectorId: number) => void;
}) {
    const [loading, setLoading] = useState(true);
    const [sectors, setSectors] = useState<Sector[]>([]);
    const [selected, setSelected] = useState<number | null>(null);

    useEffect(() => {
        let alive = true;
        setLoading(true);
        getEditorialSectors(companyId)
            .then((r: any) => { if (alive) setSectors(r?.data?.sectors ?? []); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId]);

    return (
        <Card>
            <CardBody>
                <div className="text-center mb-3">
                    <div className="avatar-md mx-auto mb-3">
                        <span className="avatar-title bg-primary-subtle text-primary rounded fs-24"><i className="ri-calendar-todo-line" /></span>
                    </div>
                    <h5 className="mb-1">Escolhe o teu ramo</h5>
                    <p className="text-muted mb-0">Isto define as datas editoriais do teu setor.</p>
                </div>

                <div className="alert alert-warning d-flex gap-2 align-items-start" role="alert">
                    <i className="ri-alert-line fs-5" />
                    <div>Podes mudar depois em <strong>Configurações</strong>, mas <strong>mudar de ramo recomeça o calendário</strong>. Escolhe com atenção.</div>
                </div>

                {loading ? (
                    <div className="text-center py-4"><Spinner color="primary" /></div>
                ) : (
                    <>
                        <Row className="g-3 justify-content-center">
                            {sectors.map((s) => {
                                const active = selected === s.id;
                                return (
                                    <Col key={s.id} xs={6} md={3}>
                                        <button
                                            type="button"
                                            onClick={() => setSelected(s.id)}
                                            disabled={busy}
                                            className="w-100 h-100 d-flex flex-column align-items-center gap-2 p-3"
                                            style={{
                                                border: `2px solid ${active ? "var(--vz-primary)" : "var(--vz-border-color)"}`,
                                                borderRadius: 12,
                                                background: active ? "var(--vz-primary-subtle)" : "var(--vz-card-bg)",
                                                cursor: "pointer",
                                            }}
                                        >
                                            <i className={`${ICONS[s.slug] ?? "ri-price-tag-3-line"} fs-2 ${active ? "text-primary" : "text-muted"}`} />
                                            <span className={`fw-semibold ${active ? "text-primary" : "text-body"}`}>{s.name}</span>
                                        </button>
                                    </Col>
                                );
                            })}
                        </Row>

                        <div className="text-center mt-4">
                            <button className="btn btn-primary" disabled={!selected || busy} onClick={() => selected && onChoose(selected)}>
                                {busy ? <><Spinner size="sm" className="me-1" /> A definir…</> : <><i className="ri-check-line me-1" /> Confirmar ramo</>}
                            </button>
                        </div>
                    </>
                )}
            </CardBody>
        </Card>
    );
}
