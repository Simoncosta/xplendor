import { useCallback, useEffect, useState } from "react";
import { Col, Row, Progress } from "reactstrap";
import { toast } from "react-toastify";

const API_URL = process.env.REACT_APP_API_URL ?? "";

interface Adset {
    id: string;
    name: string;
    status: string;
    campaign_id: string;
    campaign: { name: string };
}

interface Mapping {
    id: number;
    platform: string;
    campaign_name: string;
    adset_id: string;
    adset_name: string;
    spend_split_pct: number;
    is_active: boolean;
}

interface Props {
    companyId: number;
    carId: number | string | undefined;
}

const getToken = () => {
    const authUser = sessionStorage.getItem("authUser");
    return authUser ? JSON.parse(authUser).token : "";
};

export default function CarAdCampaignMapper({ companyId, carId }: Props) {
    const [mappings, setMappings] = useState<Mapping[]>([]);
    const [adsets, setAdsets] = useState<Adset[]>([]);
    const [loading, setLoading] = useState(false);
    const [showForm, setShowForm] = useState(false);
    const [selected, setSelected] = useState<Adset | null>(null);
    const [splitPct, setSplitPct] = useState(100);
    const [saving, setSaving] = useState(false);

    const headers = { Authorization: `Bearer ${getToken()}` };

    const fetchMappings = useCallback(async () => {
        const res = await fetch(
            `${API_URL}/companies/${companyId}/cars/${carId}/ad-campaigns`,
            { headers }
        );
        const data = await res.json();
        setMappings(data?.data ?? []);
    }, [companyId, carId]);

    const fetchAdsets = useCallback(async () => {
        setLoading(true);
        try {
            const res = await fetch(
                `${API_URL}/companies/${companyId}/integrations/meta/adsets`,
                { headers }
            );
            const data = await res.json();
            setAdsets(data?.data ?? []);
        } catch {
            toast.error("Erro ao carregar adsets do Meta.");
        } finally {
            setLoading(false);
        }
    }, [companyId]);

    useEffect(() => {
        fetchMappings();
    }, [fetchMappings]);

    const handleOpenForm = () => {
        setShowForm(true);
        if (adsets.length === 0) fetchAdsets();
    };

    const handleSave = async () => {
        if (!selected) return;
        setSaving(true);
        try {
            const res = await fetch(
                `${API_URL}/companies/${companyId}/cars/${carId}/ad-campaigns`,
                {
                    method: "POST",
                    headers: { ...headers, "Content-Type": "application/json" },
                    body: JSON.stringify({
                        platform: "meta",
                        campaign_id: selected.campaign_id,
                        campaign_name: selected.campaign?.name ?? "",
                        adset_id: selected.id,
                        adset_name: selected.name,
                        level: "adset",
                        spend_split_pct: splitPct,
                    }),
                }
            );

            if (!res.ok) {
                const data = await res.json();
                toast.error(data?.message ?? "Erro ao mapear campanha.");
                return;
            }

            toast.success("Campanha mapeada com sucesso!");
            setShowForm(false);
            setSelected(null);
            setSplitPct(100);
            fetchMappings();
        } finally {
            setSaving(false);
        }
    };

    const handleDelete = async (id: number) => {
        await fetch(
            `${API_URL}/companies/${companyId}/cars/${carId}/ad-campaigns/${id}`,
            { method: "DELETE", headers }
        );
        toast.success("Mapeamento removido.");
        fetchMappings();
    };

    const handleToggle = async (id: number) => {
        await fetch(
            `${API_URL}/companies/${companyId}/cars/${carId}/ad-campaigns/${id}/toggle`,
            { method: "PATCH", headers }
        );
        fetchMappings();
    };

    const dashed = { border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.75rem", background: "#fff" };

    return (
        <div>
            <div className="d-flex align-items-center justify-content-between mb-3">
                <h6 className="fs-13 fw-semibold mb-0">
                    <i className="ri-advertisement-line me-2 text-primary" />
                    Campanhas Meta Ads
                </h6>
                {!showForm && (
                    <button className="btn btn-soft-primary btn-sm" onClick={handleOpenForm}>
                        <i className="ri-add-line me-1" /> Mapear campanha
                    </button>
                )}
            </div>

            {/* Mapeamentos existentes */}
            {mappings.length > 0 && (
                <div className="vstack gap-2 mb-3">
                    {mappings.map((m) => (
                        <div key={m.id} style={dashed}>
                            <div className="d-flex align-items-center justify-content-between">
                                <div>
                                    <div className="d-flex align-items-center gap-2 mb-1">
                                        <i className="ri-facebook-fill text-primary fs-14" />
                                        <span className="fs-13 fw-medium">{m.adset_name || m.campaign_name}</span>
                                        {!m.is_active && (
                                            <span className="badge badge-soft-secondary fs-10">Pausado</span>
                                        )}
                                    </div>
                                    <div className="d-flex gap-3 fs-12 text-muted">
                                        <span>Conjunto de anúncios</span>
                                        <span>{m.spend_split_pct}% do spend</span>
                                    </div>
                                </div>
                                <div className="d-flex gap-1">
                                    <button
                                        className="btn btn-soft-secondary btn-sm"
                                        onClick={() => handleToggle(m.id)}
                                        title={m.is_active ? "Pausar" : "Activar"}
                                    >
                                        <i className={m.is_active ? "ri-pause-line" : "ri-play-line"} />
                                    </button>
                                    <button
                                        className="btn btn-soft-danger btn-sm"
                                        onClick={() => handleDelete(m.id)}
                                        title="Remover"
                                    >
                                        <i className="ri-delete-bin-line" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {mappings.length === 0 && !showForm && (
                <div className="text-center py-4 text-muted" style={dashed}>
                    <i className="ri-advertisement-line fs-1 d-block mb-2" />
                    <p className="fs-13 mb-0">Nenhuma campanha mapeada a este carro.</p>
                    <p className="fs-12 mb-0 mt-1">Mapeia um conjunto de anúncios para que o spend chegue automaticamente.</p>
                </div>
            )}

            {/* Formulário de mapeamento */}
            {showForm && (
                <div className="p-3 rounded" style={{ background: "#f8f9fa", border: "1px dashed #e9ebec" }}>
                    <p className="fs-12 fw-semibold text-muted text-uppercase mb-3" style={{ letterSpacing: ".05em" }}>
                        Seleccionar conjunto de anúncios
                    </p>

                    {loading ? (
                        <p className="text-muted fs-13">A carregar adsets do Meta...</p>
                    ) : (
                        <Row className="g-2">
                            {adsets.map((adset) => (
                                <Col xs={12} key={adset.id}>
                                    <div
                                        style={{
                                            ...dashed,
                                            cursor: "pointer",
                                            borderColor: selected?.id === adset.id ? "#405189" : "#e9ebec",
                                            borderStyle: selected?.id === adset.id ? "solid" : "dashed",
                                            background: selected?.id === adset.id ? "#f0f3ff" : "#fff",
                                        }}
                                        onClick={() => setSelected(adset)}
                                    >
                                        <div className="d-flex align-items-center justify-content-between">
                                            <div>
                                                <p className="fs-13 fw-medium mb-0">{adset.name}</p>
                                                <p className="fs-12 text-muted mb-0">{adset.campaign?.name}</p>
                                            </div>
                                            <span className={`badge fs-10 ${adset.status === "ACTIVE" ? "badge-soft-success" : "badge-soft-secondary"}`}>
                                                {adset.status}
                                            </span>
                                        </div>
                                    </div>
                                </Col>
                            ))}
                        </Row>
                    )}

                    {selected && (
                        <div className="mt-3">
                            <label className="fs-12 text-muted fw-semibold text-uppercase mb-1" style={{ letterSpacing: ".05em" }}>
                                % do spend atribuída a este carro
                            </label>
                            <div className="d-flex align-items-center gap-3">
                                <input
                                    type="range"
                                    min={1}
                                    max={100}
                                    step={1}
                                    value={splitPct}
                                    onChange={e => setSplitPct(Number(e.target.value))}
                                    className="flex-grow-1"
                                />
                                <span className="fw-semibold fs-14" style={{ minWidth: 40 }}>{splitPct}%</span>
                            </div>
                            {splitPct < 100 && (
                                <p className="fs-12 text-muted mt-1 mb-0">
                                    Os restantes {100 - splitPct}% podem ser atribuídos a outro carro no mesmo conjunto.
                                </p>
                            )}
                        </div>
                    )}

                    <div className="d-flex gap-2 mt-3">
                        <button
                            className="btn btn-primary btn-sm"
                            disabled={!selected || saving}
                            onClick={handleSave}
                        >
                            {saving ? "A guardar..." : "Confirmar mapeamento"}
                        </button>
                        <button
                            className="btn btn-soft-secondary btn-sm"
                            onClick={() => { setShowForm(false); setSelected(null); }}
                        >
                            Cancelar
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}