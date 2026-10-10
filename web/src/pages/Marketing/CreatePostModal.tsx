import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import { createPostFromSignal } from "helpers/laravel_helper";
import { RestaurantSignalItem } from "common/models/pingwin.model";

/**
 * XPLENDOR — F3: "Criar publicação" a partir de uma sugestão. Vem preenchido (tema, data
 * sugerida, Instagram e "Imagem única", ou "Sazonal" quando a data é um feriado ou uma
 * data de âncora) para a pessoa rever; cria uma ideia na Linha
 * Editorial. Se o mês não estiver aberto, explica e liga à Linha Editorial (nunca o abre).
 */

const NETWORK_LABELS: Record<string, string> = { instagram: "Instagram", facebook: "Facebook" };

type Props = {
    companyId: number;
    signal: RestaurantSignalItem | null;
    formats: string[];
    networks: string[];
    specialDays: Record<string, string>;
    onClose: () => void;
    onCreated: (message: string) => void;
};

export default function CreatePostModal({ companyId, signal, formats, networks, specialDays, onClose, onCreated }: Props) {
    const [title, setTitle] = useState("");
    const [date, setDate] = useState("");
    const [chosen, setChosen] = useState<string[]>(["instagram"]);
    const [format, setFormat] = useState<string>("Imagem única");
    const [formatTouched, setFormatTouched] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<{ text: string; month?: boolean } | null>(null);

    const defaultFormat = (d: string) => (specialDays?.[d] && formats.includes("Sazonal") ? "Sazonal" : "Imagem única");
    const special = specialDays?.[date];

    useEffect(() => {
        if (!signal) return;
        setTitle(signal.theme || signal.title);
        setDate(signal.suggested_date || "");
        setChosen(["instagram"]);
        setFormat(defaultFormat(signal.suggested_date || ""));
        setFormatTouched(false);
        setError(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [signal]);

    const changeDate = (d: string) => {
        setDate(d);
        if (!formatTouched) setFormat(defaultFormat(d));
    };

    const toggle = (n: string) => setChosen((c) => (c.includes(n) ? c.filter((x) => x !== n) : [...c, n]));
    const invalid = !title.trim() ? "Indique o tema." : !date ? "Indique a data." : chosen.length === 0 ? "Escolha pelo menos uma rede." : null;

    const submit = async () => {
        if (!signal || invalid) return;
        setSaving(true);
        setError(null);
        try {
            const res: any = await createPostFromSignal(companyId, { key: signal.key, title: title.trim(), publish_date: date, networks: chosen, format });
            onCreated(res?.message ?? "Ideia criada na Linha Editorial.");
        } catch (e: any) {
            const monthError = e?.errors?.publish_date?.[0];
            setError({ text: monthError || e?.errors?.title?.[0] || e?.message || "Não foi possível criar a publicação.", month: !!monthError && /não está aberto/.test(monthError) });
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal isOpen={!!signal} toggle={onClose} centered>
            <ModalHeader toggle={onClose}>Criar publicação</ModalHeader>
            <ModalBody>
                <p className="text-muted fs-13">Cria uma ideia na Linha Editorial, para a equipa trabalhar. Reveja o tema e a data.</p>
                <div className="mb-3">
                    <label htmlFor="signal-post-title" className="form-label">Tema</label>
                    <input id="signal-post-title" className="form-control" value={title} maxLength={255} onChange={(e) => setTitle(e.target.value)} />
                </div>
                <div className="mb-3">
                    <label htmlFor="signal-post-date" className="form-label">Data</label>
                    <input id="signal-post-date" type="date" className="form-control" value={date} onChange={(e) => changeDate(e.target.value)} />
                    {special && <div className="form-text">Data especial: {special}.</div>}
                </div>
                <div className="mb-3">
                    <span className="form-label d-block">Redes</span>
                    <div className="d-flex gap-3">
                        {networks.map((n) => (
                            <div key={n} className="form-check">
                                <input id={`signal-post-net-${n}`} type="checkbox" className="form-check-input" checked={chosen.includes(n)} onChange={() => toggle(n)} />
                                <label htmlFor={`signal-post-net-${n}`} className="form-check-label">{NETWORK_LABELS[n] ?? n}</label>
                            </div>
                        ))}
                    </div>
                </div>
                <div className="mb-2">
                    <label htmlFor="signal-post-format" className="form-label">Tipo de conteúdo</label>
                    <XSelect id="signal-post-format" ariaLabel="Tipo de conteúdo" value={format} onChange={(v) => { setFormat(v); setFormatTouched(true); }}
                        options={formats.map((f) => ({ value: f, label: f }))} searchable />
                </div>
                {error && (
                    <div className="alert alert-warning fs-13 mb-0 mt-3" role="alert">
                        {error.text}
                        {error.month && <> <Link to="/editorial">Abrir a Linha Editorial</Link></>}
                    </div>
                )}
            </ModalBody>
            <ModalFooter>
                <button type="button" className="btn btn-light" onClick={onClose}>Cancelar</button>
                <ReasonButton color="primary" onClick={submit} disabled={saving} reason={saving ? null : invalid}>
                    {saving ? <Spinner size="sm" /> : "Criar ideia"}
                </ReasonButton>
            </ModalFooter>
        </Modal>
    );
}
