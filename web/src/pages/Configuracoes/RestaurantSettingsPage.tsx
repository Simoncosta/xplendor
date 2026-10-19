import { useCallback, useEffect, useMemo, useState } from "react";
import { Button, Container, Modal, ModalBody, ModalFooter, ModalHeader } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import Skeleton from "Components/Common/Skeleton";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { getRestaurantSettings, updateRestaurantSettings } from "helpers/laravel_helper";
import { RestaurantSettingsResponse, SettingValue } from "common/models/settings.model";
import SettingField from "./SettingField";
import SettingsHistory from "./SettingsHistory";

/**
 * XPLENDOR — Configurações › Restauração e Auditoria: as definições da empresa que antes viviam no
 * ficheiro de configuração do servidor (food cost alvo, recontagem, transferências, loja dos
 * documentos de stock, urgências da auditoria, anulações por funcionário, documentos de caixa e
 * feriado municipal), com o histórico. Ligar as anulações por funcionário pede a autorização do dono.
 * Uma só grelha: um cartão por assunto (título e para que serve) e, em cada definição, o nome e a
 * ajuda à esquerda e o campo à direita. O Guardar fica à vista enquanto houver alterações.
 */

/** Para que serve cada assunto (uma frase). */
const SECTION_HELP: Record<string, string> = {
    menu: "Os alvos de food cost que a Engenharia de menu e a Auditoria usam para avisar dos pratos caros.",
    stock: "Quando pedir uma recontagem, quando confirmar uma transferência e em que loja se emitem os documentos de stock.",
    auditoria: "A partir de que valor um achado da Auditoria fica com urgência alta ou média, e a regra das anulações por funcionário.",
    caixa: "A leitura noturna dos documentos de caixa do PingWin (só leitura).",
    calendario: "O feriado do concelho, para o Calendário e para a previsão das vendas.",
    financas: "Para onde vai o envio dos documentos ao contabilista.",
};
export default function RestaurantSettingsPage() {
    document.title = "Restauração e Auditoria | Configurações | Xplendor";
    const companyId = useWorkingCompanyId();
    const [data, setData] = useState<RestaurantSettingsResponse | null>(null);
    const [draft, setDraft] = useState<Record<string, SettingValue>>({});
    const [saving, setSaving] = useState(false);
    const [consent, setConsent] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(async () => {
        if (!companyId) return;
        try {
            const res: any = await getRestaurantSettings(companyId);
            setData(res?.data ?? null);
            setDraft({});
        } catch (e: any) {
            setError(e?.message ?? "Não foi possível abrir as configurações.");
        }
    }, [companyId]);
    useEffect(() => { load(); }, [load]);

    const value = (key: string) => (key in draft ? draft[key] : data?.settings.find((s) => s.key === key)?.value) as SettingValue;
    const changed = useMemo(() => Object.keys(draft).filter((k) => JSON.stringify(draft[k]) !== JSON.stringify(data?.settings.find((s) => s.key === k)?.value)), [draft, data]);
    const turningOnVoids = changed.includes("audit.voids_by_employee") && draft["audit.voids_by_employee"] === true;

    const save = async (withConsent = false) => {
        if (!companyId || changed.length === 0) return;
        if (turningOnVoids && !withConsent) { setConsent(true); return; }
        setSaving(true);
        try {
            const values = Object.fromEntries(changed.map((k) => [k, draft[k]]));
            const res: any = await updateRestaurantSettings(companyId, values, withConsent);
            setData(res?.data ?? data);
            setDraft({});
            setConsent(false);
            toast.success(res?.message ?? "Configurações guardadas.");
        } catch (e: any) {
            const first = e?.errors ? Object.values(e.errors as Record<string, string[]>)[0]?.[0] : null;
            toast.error(first ?? e?.message ?? "Não foi possível guardar.");
        } finally {
            setSaving(false);
        }
    };

    const voids = data?.settings.find((s) => s.key === "audit.voids_by_employee");
    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Restauração e Auditoria" breadcrumbs={[{ label: "Configurações" }]}
                    info="As definições da restauração e da auditoria desta empresa. Cada alteração fica registada no histórico, com quem a fez e quando." />
                {error && !data ? <p className="text-danger">{error}</p> : !data ? <Skeleton variant="lines" lines={8} label="A abrir as configurações…" /> : (
                    <>
                        {Object.entries(data.sections).map(([section, title]) => {
                            const items = data.settings.filter((s) => s.section === section);
                            if (items.length === 0) return null;
                            return (
                                <PageCard key={section} title={title} flush={false} data-testid={`settings-section-${section}`}
                                    status={SECTION_HELP[section] ? <span data-testid={`settings-section-help-${section}`}>{SECTION_HELP[section]}</span> : undefined}>
                                    <div className="xp-settings-grid">
                                        {items.map((it) => (
                                            <SettingField key={it.key} item={it} value={value(it.key)} stores={data.stores}
                                                disabled={it.owner && !data.can_voids}
                                                disabledReason={it.owner && !data.can_voids ? "Só o administrador da própria empresa liga esta regra." : undefined}
                                                onChange={(v) => setDraft((d) => ({ ...d, [it.key]: v }))} />
                                        ))}
                                    </div>
                                    {section === "auditoria" && voids && <p className="text-muted fs-12 mb-0 mt-3">{data.owner_note}</p>}
                                </PageCard>
                            );
                        })}
                        {changed.length > 0 && (
                            <div className="d-flex flex-wrap align-items-center gap-2 px-3 py-2 my-3 border rounded shadow-sm" data-testid="settings-savebar"
                                style={{ position: "sticky", bottom: 12, background: "var(--vz-secondary-bg)", zIndex: 5 }}>
                                <span className="fs-13 fw-medium me-auto">{`${changed.length} ${changed.length === 1 ? "alteração" : "alterações"} por guardar.`}</span>
                                <Button color="light" disabled={saving} onClick={() => setDraft({})}>Desfazer</Button>
                                <Button color="primary" disabled={saving} onClick={() => save(false)} data-testid="settings-save">Guardar</Button>
                            </div>
                        )}
                        <PageCard title="Histórico" flush={false}><SettingsHistory rows={data.history} /></PageCard>
                    </>
                )}
            </Container>
            <Modal isOpen={consent} toggle={() => setConsent(false)} centered data-testid="settings-consent">
                <ModalHeader toggle={() => setConsent(false)}>Ligar as anulações por funcionário</ModalHeader>
                <ModalBody>
                    <p className="mb-2">{voids?.consent}</p>
                    <p className="text-muted fs-13 mb-0">A auditoria passa a comparar as anulações de cada funcionário com as dos colegas da mesma loja e semana. Os achados, com os nomes, só os vê o administrador.</p>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setConsent(false)}>Cancelar</Button>
                    <Button color="primary" disabled={saving} onClick={() => save(true)} data-testid="settings-consent-confirm">O dono autoriza: ligar</Button>
                </ModalFooter>
            </Modal>
        </div>
    );
}
