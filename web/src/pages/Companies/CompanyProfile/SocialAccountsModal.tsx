import { useEffect, useMemo, useState } from "react";
import { Badge, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { getSocialCandidates, saveSocialAccounts } from "helpers/laravel_helper";
import type { SocialCandidatePage, SocialConnectionState } from "common/models/socialConnection.model";

/**
 * Escolher que Páginas de Facebook e que contas de Instagram ficam ligadas à empresa.
 * A conta de Instagram profissional aparece através da Página a que está ligada. Com
 * mais do que uma da mesma rede, escolhe-se a principal: é essa que conta para o
 * histórico de seguidores da marca.
 */

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

type Props = {
    isOpen: boolean;
    companyId: number;
    onClose: () => void;
    onSaved: (state: SocialConnectionState) => void;
};

export default function SocialAccountsModal({ isOpen, companyId, onClose, onSaved }: Props) {
    const [pages, setPages] = useState<SocialCandidatePage[] | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [fb, setFb] = useState<string[]>([]);
    const [ig, setIg] = useState<string[]>([]);
    const [primaryFb, setPrimaryFb] = useState<string | null>(null);
    const [primaryIg, setPrimaryIg] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!isOpen || !companyId) return;
        setPages(null);
        setLoadError(null);
        getSocialCandidates(companyId)
            .then((r: any) => {
                const list: SocialCandidatePage[] = r?.data?.pages ?? [];
                setPages(list);
                let selFb = list.filter((p) => p.selected).map((p) => p.id);
                let selIg = list.filter((p) => p.instagram?.selected).map((p) => p.instagram!.id);
                // Primeira escolha com uma só Página: fica pré-marcada (e o Instagram dela).
                if (selFb.length === 0 && selIg.length === 0 && list.length === 1) {
                    selFb = [list[0].id];
                    selIg = list[0].instagram ? [list[0].instagram.id] : [];
                }
                setFb(selFb);
                setIg(selIg);
                setPrimaryFb(list.find((p) => p.is_primary)?.id ?? selFb[0] ?? null);
                setPrimaryIg(list.find((p) => p.instagram?.is_primary)?.instagram?.id ?? selIg[0] ?? null);
            })
            .catch((e: any) => setLoadError(errorMessage(e, "Não foi possível obter as Páginas da Meta.")));
    }, [isOpen, companyId]);

    const toggle = (list: string[], set: (v: string[]) => void, id: string, on: boolean) => set(on ? [...list, id] : list.filter((x) => x !== id));
    const effectivePrimaryFb = fb.includes(primaryFb ?? "") ? primaryFb : fb[0] ?? null;
    const effectivePrimaryIg = ig.includes(primaryIg ?? "") ? primaryIg : ig[0] ?? null;
    const igNames = useMemo(() => Object.fromEntries((pages ?? []).filter((p) => p.instagram).map((p) => [p.instagram!.id, p.instagram!.username ? `@${p.instagram!.username}` : p.instagram!.name ?? p.name])), [pages]);
    const fbNames = useMemo(() => Object.fromEntries((pages ?? []).map((p) => [p.id, p.name])), [pages]);

    const save = async () => {
        setSaving(true);
        try {
            const r: any = await saveSocialAccounts(companyId, { facebook: fb, instagram: ig, primary_facebook: effectivePrimaryFb, primary_instagram: effectivePrimaryIg });
            toast.success("Contas guardadas. A ler os seguidores.");
            onSaved(r?.data);
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível guardar a escolha."));
        } finally {
            setSaving(false);
        }
    };

    const primaryPicker = (label: string, ids: string[], names: Record<string, string>, value: string | null, onChange: (v: string) => void, name: string) =>
        ids.length > 1 && (
            <div className="mt-3">
                <Label className="fw-medium fs-13 mb-1">{label}</Label>
                {ids.map((id) => (
                    <div className="form-check" key={id}>
                        <Input className="form-check-input" type="radio" name={name} id={`${name}-${id}`} checked={value === id} onChange={() => onChange(id)} />
                        <Label className="form-check-label fs-13" for={`${name}-${id}`}>{names[id] ?? id}</Label>
                    </div>
                ))}
            </div>
        );

    return (
        <Modal isOpen={isOpen} toggle={!saving ? onClose : undefined} centered scrollable>
            <ModalHeader toggle={!saving ? onClose : undefined}>Escolher as Páginas e as contas de Instagram</ModalHeader>
            <ModalBody>
                <p className="text-muted fs-13">
                    Escolha o que fica ligado à empresa. A XPLENDOR só lê o número de seguidores; não publica nem altera nada.
                    A conta de Instagram profissional aparece através da Página de Facebook a que está ligada.
                </p>
                {loadError ? (
                    <div className="alert alert-warning fs-13 mb-0">{loadError}</div>
                ) : pages === null ? (
                    <div className="text-center py-3"><Spinner size="sm" /></div>
                ) : pages.length === 0 ? (
                    <div className="alert alert-light border fs-13 mb-0">
                        A autorização não deu acesso a nenhuma Página. Volte a ligar e, no Facebook, escolha as Páginas da empresa.
                    </div>
                ) : (
                    <>
                        <div className="vstack gap-2">
                            {pages.map((p) => (
                                <div key={p.id} className="border rounded p-2">
                                    <div className="form-check">
                                        <Input className="form-check-input" type="checkbox" id={`fb-${p.id}`} checked={fb.includes(p.id)}
                                            onChange={(e) => toggle(fb, setFb, p.id, e.target.checked)} />
                                        <Label className="form-check-label" for={`fb-${p.id}`}>
                                            <i className="ri-facebook-circle-line me-1 text-primary" />Página: <strong>{p.name}</strong>
                                        </Label>
                                    </div>
                                    {p.instagram ? (
                                        <div className="form-check ms-3 mt-1">
                                            <Input className="form-check-input" type="checkbox" id={`ig-${p.instagram.id}`} checked={ig.includes(p.instagram.id)}
                                                onChange={(e) => toggle(ig, setIg, p.instagram!.id, e.target.checked)} />
                                            <Label className="form-check-label" for={`ig-${p.instagram.id}`}>
                                                <i className="ri-instagram-line me-1 text-danger" />Instagram: <strong>{p.instagram.username ? `@${p.instagram.username}` : p.instagram.name}</strong>
                                            </Label>
                                        </div>
                                    ) : (
                                        <div className="text-muted fs-12 ms-4 mt-1">Sem conta de Instagram profissional ligada a esta Página.</div>
                                    )}
                                </div>
                            ))}
                        </div>
                        {primaryPicker("Página principal (conta para o histórico de seguidores)", fb, fbNames, effectivePrimaryFb, setPrimaryFb, "primary-fb")}
                        {primaryPicker("Conta de Instagram principal (conta para o histórico de seguidores)", ig, igNames, effectivePrimaryIg, setPrimaryIg, "primary-ig")}
                        {(fb.length > 0 || ig.length > 0) && (
                            <div className="d-flex flex-wrap gap-1 mt-3">
                                {fb.length > 0 && <Badge color="primary-subtle" className="text-primary fw-normal">{fb.length === 1 ? "1 Página" : `${fb.length} Páginas`}</Badge>}
                                {ig.length > 0 && <Badge color="danger-subtle" className="text-danger fw-normal">{ig.length === 1 ? "1 conta de Instagram" : `${ig.length} contas de Instagram`}</Badge>}
                            </div>
                        )}
                    </>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" className="border" onClick={onClose} disabled={saving}>Cancelar</Button>
                <Button color="primary" onClick={save} disabled={saving || !pages || (fb.length === 0 && ig.length === 0)}>
                    {saving && <Spinner size="sm" className="me-1" />}Guardar escolha
                </Button>
            </ModalFooter>
        </Modal>
    );
}
