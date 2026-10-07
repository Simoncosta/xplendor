import React, { useEffect, useState } from "react";
import { Spinner, Badge } from "reactstrap";
import { toast } from "react-toastify";
import { getCompanyModules, setCompanyModule, applyCompanyModulePreset } from "helpers/laravel_helper";

/**
 * Módulos de uma empresa (só o root), no separador "Módulos" do modal da empresa: liga e
 * desliga (respeitando as dependências; o backend bloqueia e explica) e aplica presets de
 * ramo.
 */
interface ModuleRow {
    key: string;
    label: string;
    car_specific: boolean;
    depends_on: string[];
    enabled: boolean;
    can_disable: boolean;
    blocking_dependents: string[];
}

interface HistoryRow {
    module: string;
    action: "enabled" | "disabled";
    source: "manual" | "preset" | "agency";
    note: string | null;
    user: string | null;
    at: string | null;
}

const SOURCE_LABELS: Record<string, string> = { manual: "Manual", preset: "Preset", agency: "Agência" };

interface Props {
    companyId: number;
}

const PRESET_LABELS: Record<string, string> = {
    automotive: "Automotivo",
    restaurant: "Restauração",
    base: "Base (marketing e Linha Editorial)",
};

const CompanyModulesPanel: React.FC<Props> = ({ companyId }) => {
    const [modules, setModules] = useState<ModuleRow[]>([]);
    const [presets, setPresets] = useState<string[]>([]);
    const [history, setHistory] = useState<HistoryRow[]>([]);
    const [allHistory, setAllHistory] = useState(false);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);

    const load = () => {
        if (!companyId) return;
        setLoading(true);
        getCompanyModules(companyId)
            .then((r: any) => {
                setModules(r?.data?.modules ?? []);
                setPresets(r?.data?.presets ?? []);
                setHistory(r?.data?.history ?? []);
            })
            .catch(() => toast.error("Não foi possível carregar os módulos."))
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [companyId]);

    const toggle = async (m: ModuleRow) => {
        if (!companyId) return;
        setBusy(m.key);
        try {
            const r: any = await setCompanyModule(companyId, m.key, !m.enabled);
            setModules(r?.data?.modules ?? modules);
            if (r?.data?.history) setHistory(r.data.history);
        } catch (e: any) {
            // Bloqueio de dependências → mensagem clara do backend.
            toast.error(e?.response?.data?.errors?.module_key?.[0] || e?.response?.data?.message || "Não foi possível alterar o módulo.");
        } finally {
            setBusy(null);
        }
    };

    const applyPreset = async (preset: string) => {
        if (!companyId) return;
        setBusy("preset:" + preset);
        try {
            const r: any = await applyCompanyModulePreset(companyId, preset);
            setModules(r?.data?.modules ?? modules);
            if (r?.data?.history) setHistory(r.data.history);
            toast.success(`Preset "${PRESET_LABELS[preset] ?? preset}" aplicado.`);
        } catch (e: any) {
            toast.error(e?.response?.data?.message || "Não foi possível aplicar o preset.");
        } finally {
            setBusy(null);
        }
    };

    return (
        <div data-testid="company-modules">
                <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span className="text-muted fs-13">Presets de ramo:</span>
                    {presets.map((p) => (
                        <button key={p} type="button" className="btn btn-sm btn-outline-primary" disabled={busy !== null} onClick={() => applyPreset(p)}>
                            {busy === "preset:" + p ? <Spinner size="sm" /> : PRESET_LABELS[p] ?? p}
                        </button>
                    ))}
                </div>

                {loading ? (
                    <div className="d-flex align-items-center gap-2 text-muted py-4"><Spinner size="sm" /> A carregar…</div>
                ) : (
                    <ul className="list-unstyled vstack gap-2 mb-0">
                        {modules.map((m) => {
                            const blocked = m.enabled && !m.can_disable;
                            return (
                                <li key={m.key} className="d-flex align-items-center gap-3 border rounded p-2">
                                    <div className="flex-grow-1 min-w-0">
                                        <div className="fw-medium">
                                            {m.label}
                                            <Badge color={m.car_specific ? "warning" : "info"} className="ms-2 fs-11">
                                                {m.car_specific ? "Carros" : "Transversal"}
                                            </Badge>
                                        </div>
                                        {blocked && (
                                            <small className="text-muted d-block">
                                                <i className="ri-lock-line me-1" />Depende(m) dele: {m.blocking_dependents.join(", ")}. Desligue primeiro esses.
                                            </small>
                                        )}
                                    </div>
                                    <div className="form-check form-switch flex-shrink-0">
                                        <input
                                            className="form-check-input"
                                            type="checkbox"
                                            role="switch"
                                            checked={m.enabled}
                                            disabled={busy !== null || blocked}
                                            onChange={() => toggle(m)}
                                        />
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
                {history.length > 0 && (
                    <div className="mt-4" data-testid="company-modules-history">
                        <h6 className="text-muted text-uppercase fs-12 mb-2">Histórico</h6>
                        <ul className="list-unstyled vstack gap-1 mb-0 fs-13">
                            {(allHistory ? history : history.slice(0, 8)).map((h, i) => (
                                <li key={i} className="d-flex flex-wrap gap-2 align-items-baseline">
                                    <span className="text-muted text-nowrap">{h.at ? new Date(h.at).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" }) : ""}</span>
                                    <Badge color={h.action === "enabled" ? "success" : "secondary"} className="fw-normal">{h.action === "enabled" ? "Ligado" : "Desligado"}</Badge>
                                    <strong>{h.module}</strong>
                                    <span className="text-muted">{SOURCE_LABELS[h.source] ?? h.source}{h.user ? `, ${h.user}` : ""}{h.note ? `: ${h.note}` : ""}</span>
                                </li>
                            ))}
                        </ul>
                        {history.length > 8 && (
                            <button type="button" className="btn btn-link btn-sm p-0 mt-1" onClick={() => setAllHistory((v) => !v)}>
                                {allHistory ? "Ver menos" : `Ver tudo (${history.length})`}
                            </button>
                        )}
                    </div>
                )}
        </div>
    );
};

export default CompanyModulesPanel;
