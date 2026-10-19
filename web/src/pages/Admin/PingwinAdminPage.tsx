import { useCallback, useEffect, useState } from "react";
import { Alert, Container, Input, Label } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import Skeleton from "Components/Common/Skeleton";
import InfoTip from "Components/Common/InfoTip";
import { getPingwinAdminSettings, updatePingwinCompanySettings, updatePingwinPlatformSettings } from "helpers/laravel_helper";
import { PingwinAdminResponse, SettingItem } from "common/models/settings.model";
import SettingsHistory from "pages/Configuracoes/SettingsHistory";

/**
 * XPLENDOR — Admin › Integrações › PingWin (só root): as capacidades provadas (todas as empresas),
 * e por empresa a escrita no PingWin (travão) e o modo de testes, com o histórico. O travão global
 * (PINGWIN_WRITES_ENABLED) está no servidor e só se mostra.
 */
export default function PingwinAdminPage() {
    document.title = "PingWin | Administração | Xplendor";
    const [data, setData] = useState<PingwinAdminResponse | null>(null);
    const [busy, setBusy] = useState<string | null>(null);

    const load = useCallback(async () => {
        try {
            const res: any = await getPingwinAdminSettings();
            setData(res?.data ?? null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível abrir a integração PingWin.");
        }
    }, []);
    useEffect(() => { load(); }, [load]);

    const change = async (key: string, value: boolean, companyId?: number) => {
        setBusy(`${companyId ?? "p"}:${key}`);
        try {
            if (companyId) await updatePingwinCompanySettings(companyId, { [key]: value });
            else await updatePingwinPlatformSettings({ [key]: value });
            toast.success("Guardado.");
            await load();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar.");
        } finally {
            setBusy(null);
        }
    };
    const toggle = (s: SettingItem, companyId?: number) => {
        const id = `pw-${companyId ?? "p"}-${s.key.replace(/\W/g, "-")}`;
        return (
            <div className="form-check form-switch mb-2" key={s.key} data-testid={id}>
                <Input id={id} type="switch" role="switch" className="form-check-input" checked={!!s.value} disabled={busy !== null}
                    onChange={(e) => change(s.key, e.target.checked, companyId)} />
                <Label for={id} className="form-check-label d-inline-flex align-items-center gap-1">{s.label}<InfoTip text={s.info} label={`Sobre: ${s.label}`} /></Label>
            </div>
        );
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="PingWin" breadcrumbs={[{ label: "Administração" }, { label: "Integrações" }]}
                    info="As capacidades provadas no PingWin (valem para todas as empresas) e, por empresa, a escrita e o modo de testes. Cada alteração fica no histórico." />
                {!data ? <Skeleton variant="lines" lines={8} label="A abrir…" /> : (
                    <>
                        <Alert color={data.global_writes_enabled ? "success" : "danger"} className="fs-13" data-testid="pw-global">
                            {data.global_writes_enabled
                                ? "Travão global: a escrita no PingWin está ligada na plataforma. Cada empresa tem o seu travão em baixo."
                                : "Travão global: a escrita no PingWin está desligada em toda a plataforma (PINGWIN_WRITES_ENABLED=false). Nenhuma empresa grava nada, seja qual for a definição em baixo."}
                        </Alert>
                        <PageCard title="Capacidades provadas" flush={false} info="Uma capacidade só se usa depois de provada no PingWin com os artigos de teste. Vale para todas as empresas.">
                            {data.platform.map((s) => toggle(s))}
                        </PageCard>
                        <PageCard title="Por empresa" flush={false}>
                            {data.companies.length === 0 && <p className="text-muted mb-0">Nenhuma empresa com o PingWin ligado.</p>}
                            {data.companies.map((c) => (
                                <div key={c.id} className="border rounded p-3 mb-2" data-testid={`pw-company-${c.id}`}>
                                    <div className="fw-semibold mb-2">{c.name}</div>
                                    {c.settings.map((s) => toggle(s, c.id))}
                                    {c.legacy && <div className="text-muted fs-12">Ainda sem escolha gravada: valem as listas antigas do ficheiro de configuração do servidor.</div>}
                                    {c.block && <div className="text-danger fs-12">{c.block.reason}</div>}
                                </div>
                            ))}
                        </PageCard>
                        <PageCard title="Histórico" flush={false}><SettingsHistory rows={data.history} showCompany /></PageCard>
                    </>
                )}
            </Container>
        </div>
    );
}
