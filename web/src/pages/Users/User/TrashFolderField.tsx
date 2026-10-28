import { useEffect, useState } from "react";
import { Button, Label, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import XSelect from "Components/Common/Select";
import Skeleton from "Components/Common/Skeleton";
import { getMailboxTrashFolder, setMailboxTrashFolder } from "helpers/laravel_helper";
import { apiErrorMessage } from "helpers/apiError";

type TrashInfo = { marked: string | null; chosen: string | null; folders: { path: string; name: string }[] };

/**
 * E-mail 10b · "Pasta do Lixo", nas definições da caixa. Se o servidor marca uma pasta com \Trash, é
 * essa que a XPLENDOR usa: aparece só para informação. Se não marca nenhuma, escolhe-se uma das
 * pastas que já existem na caixa (a XPLENDOR nunca cria pastas). Sem nenhuma, não se apaga.
 */
export default function TrashFolderField({ companyId }: { companyId: number }) {
    const [info, setInfo] = useState<TrashInfo | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [choice, setChoice] = useState("");
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        let alive = true;
        getMailboxTrashFolder(companyId)
            .then((r: any) => { if (!alive) return; setInfo(r?.data ?? null); setChoice(r?.data?.chosen ?? ""); })
            .catch((e: any) => { if (alive) setError(apiErrorMessage(e, "Não foi possível ler as pastas da caixa.")); });
        return () => { alive = false; };
    }, [companyId]);

    const save = async () => {
        setSaving(true);
        try {
            const r: any = await setMailboxTrashFolder(companyId, choice || null);
            setInfo((i) => (i ? { ...i, chosen: r?.data?.chosen ?? null } : i));
            toast.success(r?.message || "Pasta do Lixo guardada.");
        } catch (e: any) {
            toast.error(apiErrorMessage(e, "Não foi possível guardar a pasta do Lixo."));
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="mb-3" data-testid="mailbox-trash-folder">
            <Label className="form-label" htmlFor="mailbox-trash-folder-select">Pasta do Lixo</Label>
            {error ? (
                <p className="text-muted fs-13 mb-0">{error}</p>
            ) : !info ? (
                <Skeleton variant="lines" lines={1} label="" />
            ) : info.marked ? (
                <p className="fs-13 mb-0" data-testid="mailbox-trash-marked">
                    <i className="ri-delete-bin-5-line me-1" /><strong>{info.marked}</strong>
                    <span className="text-muted"> · o servidor marca esta pasta como Lixo (\Trash): é para ela que vão as mensagens apagadas.</span>
                </p>
            ) : (
                <>
                    <div className="d-flex gap-2 align-items-center" style={{ maxWidth: 520 }}>
                        <div className="flex-grow-1">
                            <XSelect id="mailbox-trash-folder-select" ariaLabel="Pasta do Lixo" value={choice} onChange={(v) => setChoice(String(v))}
                                options={[{ value: "", label: "Nenhuma (não se apagam mensagens)" }, ...info.folders.map((f) => ({ value: f.path, label: f.name === f.path ? f.path : `${f.name} (${f.path})` }))]} />
                        </div>
                        <Button size="sm" color="primary" onClick={save} disabled={saving || choice === (info.chosen ?? "")} data-testid="mailbox-trash-save">
                            {saving ? <Spinner size="sm" /> : "Guardar"}
                        </Button>
                    </div>
                    <small className="text-muted d-block mt-1">
                        O servidor desta caixa não marca nenhuma pasta como Lixo. Escolha uma das pastas que já existem: as mensagens apagadas na XPLENDOR vão para lá (nunca se apagam de vez).
                    </small>
                </>
            )}
        </div>
    );
}
