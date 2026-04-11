import { Fragment, useCallback, useEffect, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Badge, Col, Row } from "reactstrap";
import { toast } from "react-toastify";
import {
    deleteCarAdCampaign,
    getCarAdCampaigns,
    getMetaAdsets,
    storeCarAdCampaign,
    toggleCarAdCampaign,
} from "slices/metaAds/thunk";

interface MetaAd {
    id: string;
    name: string;
    status: string;
    campaign_id: string;
    adset_id: string;
    level: "ad";
}

interface MetaAdset {
    id: string;
    name: string;
    status: string;
    campaign_id: string;
    level: "adset";
    ads: MetaAd[];
}

interface MetaCampaign {
    id: string;
    name: string;
    status: string;
    level: "campaign";
    adsets: MetaAdset[];
}

interface Mapping {
    id: number;
    platform: string;
    campaign_id: string;
    campaign_name: string | null;
    adset_id: string | null;
    adset_name: string | null;
    ad_id: string | null;
    ad_name: string | null;
    level: "campaign" | "adset" | "ad";
    spend_split_pct: number;
    is_active: boolean;
}

interface SelectedTarget {
    level: "campaign" | "adset" | "ad";
    campaign_id: string;
    campaign_name: string;
    adset_id?: string | null;
    adset_name?: string | null;
    ad_id?: string | null;
    ad_name?: string | null;
    status: string;
}

interface Props {
    companyId: number;
    carId: number | string | undefined;
}

const selectMetaAdsState = (state: any) => state.MetaAds;

const selectMetaAdsCampaignMapperViewModel = createSelector(
    [selectMetaAdsState],
    (metaAdsState) => ({
        mappings: metaAdsState.data.carAdCampaigns as Mapping[],
        campaignTree: metaAdsState.data.adsets as MetaCampaign[],
        loading: metaAdsState.loading.sync,
        saving: metaAdsState.loading.create,
    })
);

export default function CarAdCampaignMapper({ companyId, carId }: Props) {
    const dispatch: any = useDispatch();
    const [showForm, setShowForm] = useState(false);
    const [selected, setSelected] = useState<SelectedTarget | null>(null);
    const [splitPct, setSplitPct] = useState(100);
    const { mappings, campaignTree, loading, saving } = useSelector(selectMetaAdsCampaignMapperViewModel);

    const fetchMappings = useCallback(async () => {
        if (!companyId || !carId) return;

        await dispatch(getCarAdCampaigns({ companyId, carId })).unwrap();
    }, [carId, companyId, dispatch]);

    const fetchAdsets = useCallback(async () => {
        if (!companyId) return;

        try {
            await dispatch(getMetaAdsets({ companyId })).unwrap();
        } catch {
            toast.error("Erro ao carregar estrutura do Meta.");
        }
    }, [companyId, dispatch]);

    useEffect(() => {
        fetchMappings().catch(() => {
            toast.error("Erro ao carregar mapeamentos.");
        });
    }, [fetchMappings]);

    const handleOpenForm = () => {
        setShowForm(true);
        if (campaignTree.length === 0) fetchAdsets();
    };

    const handleSave = async () => {
        if (!selected) return;

        try {
            await dispatch(storeCarAdCampaign({
                companyId,
                carId: carId!,
                data: {
                    platform: "meta",
                    campaign_id: selected.campaign_id,
                    campaign_name: selected.campaign_name,
                    adset_id: selected.adset_id ?? null,
                    adset_name: selected.adset_name ?? null,
                    ad_id: selected.ad_id ?? null,
                    ad_name: selected.ad_name ?? null,
                    level: selected.level,
                    spend_split_pct: splitPct,
                },
            })).unwrap();

            toast.success("Campanha mapeada com sucesso!");
            setShowForm(false);
            setSelected(null);
            setSplitPct(100);
            await fetchMappings();
        } catch (error: any) {
            toast.error(error?.message ?? error ?? "Erro ao mapear campanha.");
        }
    };

    const handleDelete = async (id: number) => {
        try {
            await dispatch(deleteCarAdCampaign({ companyId, carId: carId!, id })).unwrap();
            toast.success("Mapeamento removido.");
            await fetchMappings();
        } catch {
            toast.error("Erro ao remover mapeamento.");
        }
    };

    const handleToggle = async (id: number) => {
        try {
            await dispatch(toggleCarAdCampaign({ companyId, carId: carId!, id })).unwrap();
            await fetchMappings();
        } catch {
            toast.error("Erro ao actualizar mapeamento.");
        }
    };

    const dashed = { border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.75rem", background: "#fff" };
    const activeBadgeStyle = { background: "#198754", color: "#fff", border: "1px solid #146c43" };
    const pausedBadgeStyle = { background: "#dc3545", color: "#fff", border: "1px solid #b02a37" };

    const getLevelLabel = (level: "campaign" | "adset" | "ad") => {
        if (level === "ad") return "Anuncio";
        if (level === "adset") return "Conjunto";
        return "Campanha";
    };

    const getMappingTitle = (mapping: Mapping) => {
        if (mapping.level === "ad") {
            return mapping.ad_name || mapping.ad_id || mapping.adset_name || mapping.campaign_name || mapping.campaign_id;
        }

        if (mapping.level === "adset") {
            return mapping.adset_name || mapping.adset_id || mapping.campaign_name || mapping.campaign_id;
        }

        return mapping.campaign_name || mapping.campaign_id;
    };

    const getMetaStatusBadge = (status: string) => {
        const normalized = status?.toUpperCase?.() || "UNKNOWN";
        const isActive = normalized === "ACTIVE";

        return (
            <span className="badge fs-10 fw-semibold" style={isActive ? activeBadgeStyle : pausedBadgeStyle}>
                {normalized}
            </span>
        );
    };

    const renderSelectableCard = (
        target: SelectedTarget,
        title: string,
        subtitle: string,
        indent: number
    ) => {
        const isSelected = selected?.level === target.level
            && selected?.campaign_id === target.campaign_id
            && (selected?.adset_id ?? null) === (target.adset_id ?? null)
            && (selected?.ad_id ?? null) === (target.ad_id ?? null);

        return (
            <Col xs={12} key={`${target.level}-${target.campaign_id}-${target.adset_id ?? "none"}-${target.ad_id ?? "none"}`}>
                <div
                    style={{
                        ...dashed,
                        cursor: "pointer",
                        marginLeft: indent,
                        borderColor: isSelected ? "#405189" : "#e9ebec",
                        borderStyle: isSelected ? "solid" : "dashed",
                        background: isSelected ? "#f0f3ff" : "#fff",
                    }}
                    onClick={() => setSelected(target)}
                >
                    <div className="d-flex align-items-start justify-content-between gap-3">
                        <div>
                            <div className="d-flex align-items-center gap-2 flex-wrap mb-1">
                                <Badge color="light" className="text-dark border">{getLevelLabel(target.level)}</Badge>
                                <span className="fs-13 fw-medium">{title}</span>
                            </div>
                            <p className="fs-12 text-muted mb-0">{subtitle}</p>
                        </div>
                        {getMetaStatusBadge(target.status)}
                    </div>
                </div>
            </Col>
        );
    };

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
                                        <span className="fs-13 fw-medium">{getMappingTitle(m)}</span>
                                        <Badge color="light" className="text-dark border">{getLevelLabel(m.level)}</Badge>
                                        <span className="badge fs-10 fw-semibold" style={m.is_active ? activeBadgeStyle : pausedBadgeStyle}>
                                            {m.is_active ? "ACTIVE" : "PAUSED"}
                                        </span>
                                    </div>
                                    <div className="d-flex gap-3 fs-12 text-muted">
                                        <span>{getLevelLabel(m.level)}</span>
                                        <span>Peso {m.spend_split_pct}% na atribuição</span>
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
                    <p className="fs-12 mb-0 mt-1">Mapeia uma campanha, conjunto ou anuncio para que o spend chegue automaticamente.</p>
                </div>
            )}

            {/* Formulário de mapeamento */}
            {showForm && (
                <div className="p-3 rounded" style={{ background: "#f8f9fa", border: "1px dashed #e9ebec" }}>
                    <p className="fs-12 fw-semibold text-muted text-uppercase mb-3" style={{ letterSpacing: ".05em" }}>
                        Seleccionar target Meta Ads
                    </p>

                    {loading ? (
                        <p className="text-muted fs-13">A carregar campanhas, conjuntos e anuncios do Meta...</p>
                    ) : (
                        <Row className="g-2">
                            {campaignTree.map((campaign) => (
                                <Fragment key={campaign.id}>
                                    {renderSelectableCard(
                                        {
                                            level: "campaign",
                                            campaign_id: campaign.id,
                                            campaign_name: campaign.name,
                                            status: campaign.status,
                                        },
                                        campaign.name || campaign.id,
                                        "Campanha completa",
                                        0
                                    )}
                                    {campaign.adsets.map((adset) => (
                                        <Fragment key={adset.id}>
                                            {renderSelectableCard(
                                                {
                                                    level: "adset",
                                                    campaign_id: campaign.id,
                                                    campaign_name: campaign.name,
                                                    adset_id: adset.id,
                                                    adset_name: adset.name,
                                                    status: adset.status,
                                                },
                                                adset.name || adset.id,
                                                `Conjunto da campanha ${campaign.name || campaign.id}`,
                                                22
                                            )}
                                            {adset.ads.map((ad) => (
                                                renderSelectableCard(
                                                    {
                                                        level: "ad",
                                                        campaign_id: campaign.id,
                                                        campaign_name: campaign.name,
                                                        adset_id: adset.id,
                                                        adset_name: adset.name,
                                                        ad_id: ad.id,
                                                        ad_name: ad.name,
                                                        status: ad.status,
                                                    },
                                                    ad.name || ad.id,
                                                    `Anuncio do conjunto ${adset.name || adset.id}`,
                                                    44
                                                )
                                            ))}
                                        </Fragment>
                                    ))}
                                </Fragment>
                            ))}
                        </Row>
                    )}

                    {selected && (
                        <div className="mt-3">
                            <div className="mb-2">
                                <Badge color="light" className="text-dark border me-2">{getLevelLabel(selected.level)}</Badge>
                                <span className="fs-13 fw-medium">
                                    {selected.ad_name || selected.adset_name || selected.campaign_name || selected.campaign_id}
                                </span>
                            </div>
                            <label className="fs-12 text-muted fw-semibold text-uppercase mb-1" style={{ letterSpacing: ".05em" }}>
                                Peso relativo deste carro no target
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
                                    Este valor passa a funcionar como peso relativo para repartir métricas e spend entre carros do mesmo target.
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
