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
    variant = "first",
    currentSectorId,
}: {
    companyId: number;
    busy?: boolean;
    onChoose: (sectorId: number) => void;
    // "first" = 1.ª escolha (B1); "change" = troca (B3b, assinala o ramo atual).
    variant?: "first" | "change";
    currentSectorId?: number;
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

    const inner = (
        <>
            {/* Cabeçalho ilustrado só na 1.ª escolha (no calendário). Nas Configurações
                (variante "change") o contexto já existe → sem ícone/título/subtexto. */}
            {variant === "first" && (
                <div className="text-center mb-3">
                    <div className="avatar-md mx-auto mb-3">
                        <span className="avatar-title bg-primary-subtle text-primary rounded fs-24"><i className="ri-calendar-todo-line" /></span>
                    </div>
                    <h5 className="mb-1">Escolhe o teu ramo</h5>
                    <p className="text-muted mb-0">Isto define as datas editoriais do teu setor.</p>
                </div>
            )}

            {variant === "change" ? (
                <div className="alert alert-danger d-flex gap-2 align-items-start" role="alert">
                    <i className="ri-error-warning-line fs-5" />
                    <div><strong>Mudar de ramo é irreversível</strong> e recomeça o calendário. Vais confirmar no passo seguinte.</div>
                </div>
            ) : (
                <div className="alert alert-warning d-flex gap-2 align-items-start" role="alert">
                    <i className="ri-alert-line fs-5" />
                    <div>Podes mudar depois em <strong>Configurações</strong>, mas <strong>mudar de ramo recomeça o calendário</strong>. Escolhe com atenção.</div>
                </div>
            )}

            {loading ? (
                <div className="text-center py-4"><Spinner color="primary" /></div>
            ) : (
                <>
                    <Row className="g-3 justify-content-center">
                        {sectors.map((s) => {
                            const active = selected === s.id;
                            const isCurrent = variant === "change" && currentSectorId === s.id;
                            const disabled = busy || isCurrent; // não deixar escolher o ramo atual
                            return (
                                <Col key={s.id} xs={6} md={3}>
                                    <button
                                        type="button"
                                        onClick={() => !isCurrent && setSelected(s.id)}
                                        disabled={disabled}
                                        className="w-100 h-100 d-flex flex-column align-items-center gap-2 p-3 position-relative"
                                        style={{
                                            border: `2px solid ${active ? "var(--vz-primary)" : "var(--vz-border-color)"}`,
                                            borderRadius: 12,
                                            background: active ? "var(--vz-primary-subtle)" : "var(--vz-card-bg)",
                                            cursor: isCurrent ? "not-allowed" : "pointer",
                                            opacity: isCurrent ? 0.55 : 1,
                                        }}
                                    >
                                        <i className={`${ICONS[s.slug] ?? "ri-price-tag-3-line"} fs-2 ${active ? "text-primary" : "text-muted"}`} />
                                        <span className={`fw-semibold ${active ? "text-primary" : "text-body"}`}>{s.name}</span>
                                        {isCurrent && <span className="badge bg-secondary-subtle text-secondary position-absolute top-0 end-0 m-1">atual</span>}
                                    </button>
                                </Col>
                            );
                        })}
                    </Row>

                    <div className="text-center mt-4">
                        <button className="btn btn-primary" disabled={!selected || busy} onClick={() => selected && onChoose(selected)}>
                            {busy
                                ? <><Spinner size="sm" className="me-1" /> A definir…</>
                                : variant === "change"
                                    ? <><i className="ri-arrow-right-line me-1" /> Continuar</>
                                    : <><i className="ri-check-line me-1" /> Confirmar ramo</>}
                        </button>
                    </div>
                </>
            )}
        </>
    );

    // "change" (Configurações): direto, sem card à volta (evita branco-dentro-de-branco).
    // "first" (calendário): mantém a moldura ilustrada.
    return variant === "change" ? inner : <Card><CardBody>{inner}</CardBody></Card>;
}
