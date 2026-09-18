import React, { useEffect, useState } from "react";
import { Modal, ModalHeader, ModalBody, ModalFooter, Spinner, Badge } from "reactstrap";
import { toast } from "react-toastify";
import { getCompanyModules, setCompanyModule, applyCompanyModulePreset } from "helpers/laravel_helper";

/**
 * XPLENDOR — Gestão de MÓDULOS de uma empresa (só super-admin). Liga/desliga
 * (respeitando a teia de dependências — o backend bloqueia e explica) e aplica
 * presets de ramo. Incremento 1: só a estrutura (não esconde secções ainda).
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

interface Props {
    isOpen: boolean;
    companyId: number | null;
    companyName?: string;
    onClose: () => void;
}

const PRESET_LABELS: Record<string, string> = {
    automotive: "Automotivo",
    restaurant: "Restauração",
};

const CompanyModulesModal: React.FC<Props> = ({ isOpen, companyId, companyName, onClose }) => {
    const [modules, setModules] = useState<ModuleRow[]>([]);
    const [presets, setPresets] = useState<string[]>([]);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);

    const load = () => {
        if (!companyId) return;
        setLoading(true);
        getCompanyModules(companyId)
            .then((r: any) => {
                setModules(r?.data?.modules ?? []);
                setPresets(r?.data?.presets ?? []);
            })
            .catch(() => toast.error("Não foi possível carregar os módulos."))
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        if (isOpen && companyId) load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen, companyId]);

    const toggle = async (m: ModuleRow) => {
        if (!companyId) return;
        setBusy(m.key);
        try {
            const r: any = await setCompanyModule(companyId, m.key, !m.enabled);
            setModules(r?.data?.modules ?? modules);
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
            toast.success(`Preset "${PRESET_LABELS[preset] ?? preset}" aplicado.`);
        } catch (e: any) {
            toast.error(e?.response?.data?.message || "Não foi possível aplicar o preset.");
        } finally {
            setBusy(null);
        }
    };

    return (
        <Modal isOpen={isOpen} toggle={onClose} centered scrollable>
            <ModalHeader toggle={onClose}>Módulos — {companyName ?? "empresa"}</ModalHeader>
            <ModalBody>
                <div className="d-flex align-items-center gap-2 mb-3">
                    <span className="text-muted fs-13">Presets de ramo:</span>
                    {presets.map((p) => (
                        <button key={p} type="button" className="btn btn-sm btn-soft-primary" disabled={busy !== null} onClick={() => applyPreset(p)}>
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
                                                <i className="ri-lock-line me-1" />Depende(m) dele: {m.blocking_dependents.join(", ")} — desliga primeiro esses.
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
            </ModalBody>
            <ModalFooter>
                <button type="button" className="btn btn-light" onClick={onClose}>Fechar</button>
            </ModalFooter>
        </Modal>
    );
};

export default CompanyModulesModal;
