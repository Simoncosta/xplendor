import { useCallback, useEffect, useMemo, useState } from "react";
import { Spinner, Modal, ModalHeader, ModalBody, ModalFooter, Button, Input, Alert } from "reactstrap";
import { toast } from "react-toastify";
import { getEditorialCalendar, getEditorialSectors, changeEditorialSector } from "helpers/laravel_helper";
import SectorChooser from "./SectorChooser";

/**
 * XPLENDOR — Linha Editorial (B3b): TROCA de ramo, self-service, nas configurações da
 * empresa. É destrutiva: aviso forte + confirmação consciente ("confirmo", tolerante a
 * maiúsculas/espaços). Reutiliza o SectorChooser (variante "change"). A 1.ª escolha
 * continua a viver no calendário; aqui é SÓ a troca (exige já haver ramo).
 */

type Sector = { id: number; name: string; slug: string };

export default function EditorialSectorSettings({ companyId }: { companyId: number }) {
    const [loading, setLoading] = useState(true);
    const [current, setCurrent] = useState<Sector | null>(null);
    const [sectors, setSectors] = useState<Sector[]>([]);
    const [choosing, setChoosing] = useState(true);            // mostra o SectorChooser
    const [pending, setPending] = useState<Sector | null>(null); // ramo escolhido → modal de confirmação
    const [confirmText, setConfirmText] = useState("");
    const [saving, setSaving] = useState(false);

    const load = useCallback(async () => {
        if (!companyId) { setLoading(false); return; }
        setLoading(true);
        try {
            const [cal, secs]: any = await Promise.all([
                getEditorialCalendar(companyId),
                getEditorialSectors(companyId),
            ]);
            const d = cal?.data ?? {};
            setCurrent(d.has_sector ? d.sector : null);
            setSectors(secs?.data?.sectors ?? []);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar o ramo.");
        } finally {
            setLoading(false);
        }
    }, [companyId]);

    useEffect(() => { load(); }, [load]);

    // O cliente escolheu um novo ramo no chooser → abre a confirmação consciente.
    const onPick = (sectorId: number) => {
        const s = sectors.find((x) => x.id === sectorId) ?? null;
        setPending(s);
        setConfirmText("");
    };

    // Tolerância no texto, rigor na ação: "Confirmo", "CONFIRMO", com espaços → válido.
    const confirmValid = useMemo(() => confirmText.trim().toLowerCase() === "confirmo", [confirmText]);

    const doChange = async () => {
        if (!pending || !confirmValid) return;
        setSaving(true);
        try {
            await changeEditorialSector(companyId, pending.id);
            toast.success(`Ramo trocado para ${pending.name}.`);
            setPending(null);
            setChoosing(false);
            await load();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível trocar de ramo.");
        } finally {
            setSaving(false);
        }
    };

    if (loading) return <div className="text-center py-4"><Spinner color="primary" /></div>;

    // Sem ramo ainda → a 1.ª escolha faz-se no calendário, não aqui.
    if (!current) {
        return (
            <Alert color="info" className="mb-0">
                Ainda não escolheste um ramo. Faz a <strong>primeira escolha</strong> no ecrã da Linha Editorial (menu Marketing → Linha Editorial).
            </Alert>
        );
    }

    return (
        <div>
            {/* "Ramo atual" direto na aba (sem card — o card do Perfil já é a moldura). */}
            <div className="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 pb-3 border-bottom">
                <div>
                    <h6 className="text-uppercase text-muted fs-12 mb-1" style={{ letterSpacing: "0.05em" }}>Ramo atual: {current.name}</h6>
                </div>
            </div>

            {/* Troca direta: o seletor entra na própria aba, sem moldura extra. A confirmação
                consciente ("confirmo", no modal) continua a ser o guarda-redes antes de aplicar. */}
            {choosing && (
                <SectorChooser
                    companyId={companyId}
                    variant="change"
                    currentSectorId={current.id}
                    busy={saving}
                    onChoose={onPick}
                />
            )}

            {/* Confirmação consciente — lista o que perde / mantém / muda + campo "confirmo". */}
            <Modal isOpen={!!pending} toggle={() => !saving && setPending(null)} centered>
                <ModalHeader toggle={() => !saving && setPending(null)}>
                    <span className="text-danger"><i className="ri-error-warning-line me-1" />Confirmar troca de ramo</span>
                </ModalHeader>
                <ModalBody>
                    {pending && (
                        <>
                            <p className="mb-3">
                                Vais mudar de <strong>{current.name}</strong> para <strong>{pending.name}</strong>. Isto é <strong>irreversível</strong>.
                            </p>
                            <ul className="list-unstyled vstack gap-2 mb-3">
                                <li className="d-flex gap-2"><i className="ri-close-circle-line text-danger fs-5" /><span><strong>Fecha</strong> todos os meses que abriste.</span></li>
                                <li className="d-flex gap-2"><i className="ri-close-circle-line text-danger fs-5" /><span><strong>Apaga</strong> as datas que escondeste.</span></li>
                                <li className="d-flex gap-2"><i className="ri-checkbox-circle-line text-success fs-5" /><span>As tuas <strong>datas próprias</strong> (aniversário, etc.) <strong>mantêm-se</strong>.</span></li>
                                <li className="d-flex gap-2"><i className="ri-arrow-right-circle-line text-primary fs-5" /><span>As datas do ramo <strong>{pending.name}</strong> passam a aparecer.</span></li>
                            </ul>
                            <label className="form-label mb-1">Para confirmar, escreve <strong>confirmo</strong>:</label>
                            <Input
                                value={confirmText}
                                onChange={(e) => setConfirmText(e.target.value)}
                                placeholder="confirmo"
                                disabled={saving}
                                onKeyDown={(e) => { if (e.key === "Enter" && confirmValid) doChange(); }}
                                autoFocus
                            />
                        </>
                    )}
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setPending(null)} disabled={saving}>Cancelar</Button>
                    <Button color="danger" onClick={doChange} disabled={!confirmValid || saving}>
                        {saving ? <Spinner size="sm" /> : <><i className="ri-repeat-2-line me-1" />Trocar de ramo</>}
                    </Button>
                </ModalFooter>
            </Modal>
        </div>
    );
}
