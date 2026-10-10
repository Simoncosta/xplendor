import { useEffect, useMemo, useState } from "react";
import { Alert, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import ReasonButton from "Components/Common/ReasonButton";
import { createPermissionProfile, previewPermissionProfile, updatePermissionProfile } from "helpers/laravel_helper";
import {
    ACTION_LABEL, AreaSummary, CatalogArea, PermissionProfile, ProfilePreview, ProfileSide, ProfilesPayload, SIDE_LABEL,
} from "common/models/permissionProfile.model";

/**
 * ACL (F5, D13): "Novo perfil" parte de uma sugestão ou de um perfil vazio; tudo é editável e a
 * sugestão nunca é imposta. Mostra, área a área e em linguagem simples, o que o perfil dá; antes
 * de gravar, o backend devolve as permissões efetivas (o que se ignora deste lado e as áreas
 * sem efeito por o módulo não estar ativo).
 */
type Step = "inicio" | "permissoes" | "rever";

/** Permissões base: quem trabalha na empresa tem-nas sempre (o backend dá-as a todos). */
const BASE = ["empresa.ver"];

const VERBS: Record<string, string> = { ver: "ver", criar: "criar", editar: "editar", aprovar: "aprovar", apagar: "apagar", configurar: "configurar" };
const joinWords = (w: string[]) => (w.length <= 1 ? w[0] ?? "" : `${w.slice(0, -1).join(", ")} e ${w[w.length - 1]}`);

/** A mesma frase do backend (ProfileSuggestions::describe), para ir mostrando enquanto se edita. */
export function describeArea(area: CatalogArea, selected: Set<string>): string {
    const has = area.actions.filter((a) => selected.has(`${area.area}.${a}`) || BASE.includes(`${area.area}.${a}`));
    const missing = area.actions.filter((a) => !has.includes(a));
    if (has.length === 0) return "Não vê.";
    if (missing.length === 0) return `Pode ${joinWords(has.map((a) => VERBS[a]))}.`;
    return `Pode ${joinWords(has.map((a) => VERBS[a]))}; não pode ${joinWords(missing.map((a) => VERBS[a]))}.`;
}

type Props = {
    companyId: number;
    data: ProfilesPayload;
    /** null = fechado; "new" = novo; um perfil = editar; { from } = novo a partir deste */
    target: null | "new" | PermissionProfile | { from: PermissionProfile };
    onClose: () => void;
    onSaved: () => void;
};

export default function ProfileEditorModal({ companyId, data, target, onClose, onSaved }: Props) {
    const editing = target && target !== "new" && !("from" in target) ? target : null;
    const sides = useMemo<ProfileSide[]>(() => {
        const out: ProfileSide[] = ["cliente"];
        if (data.management) out.push("teto");
        if (data.is_agency) out.push("agencia");
        return out;
    }, [data]);

    const [step, setStep] = useState<Step>("inicio");
    const [side, setSide] = useState<ProfileSide>("cliente");
    const [from, setFrom] = useState<PermissionProfile | null>(null);
    const [name, setName] = useState("");
    const [description, setDescription] = useState("");
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [preview, setPreview] = useState<ProfilePreview | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!target) return;
        setError(null);
        setPreview(null);
        if (editing) {
            setSide(editing.side); setFrom(null); setName(editing.name); setDescription(editing.description ?? "");
            setSelected(new Set(editing.permissions)); setStep("permissoes");
        } else if (target !== "new" && "from" in target) {
            const f = target.from;
            setSide(f.side); setFrom(f); setName(f.is_suggestion ? f.name : `${f.name} (cópia)`); setDescription(f.description ?? "");
            setSelected(new Set(f.permissions)); setStep("permissoes");
        } else {
            setSide("cliente"); setFrom(null); setName(""); setDescription(""); setSelected(new Set()); setStep("inicio");
        }
    }, [target, editing]);

    const allowed = useMemo(() => new Set(data.allowed[side] ?? []), [data, side]);
    const suggestions = data.profiles.filter((p) => p.is_suggestion && p.side === side);
    const areas = data.catalog.filter((a) => a.actions.some((act) => allowed.has(`${a.area}.${act}`)));

    const startFrom = (p: PermissionProfile | null) => {
        setFrom(p);
        setName(p ? p.name : "");
        setDescription(p?.description ?? "");
        setSelected(new Set((p?.permissions ?? []).filter((x) => allowed.has(x))));
        setStep("permissoes");
    };

    const toggle = (perm: string) => setSelected((prev) => {
        const next = new Set(prev);
        if (next.has(perm)) next.delete(perm); else next.add(perm);
        return next;
    });

    const review = async () => {
        setBusy(true); setError(null);
        try {
            const r: any = await previewPermissionProfile(companyId, { side, permissions: Array.from(selected) });
            setPreview(r?.data ?? null);
            setStep("rever");
        } catch (e: any) {
            setError(e?.message ?? "Não foi possível rever o perfil.");
        } finally { setBusy(false); }
    };

    const save = async () => {
        setBusy(true); setError(null);
        try {
            const payload = { name: name.trim(), description: description.trim() || null, permissions: Array.from(selected) };
            if (editing) await updatePermissionProfile(companyId, editing.id, payload);
            else await createPermissionProfile(companyId, { ...payload, side, from_profile_id: from?.id ?? null });
            toast.success(editing ? "Perfil guardado." : "Perfil criado.");
            onSaved();
        } catch (e: any) {
            const first = e?.errors ? (Object.values(e.errors).flat()[0] as string) : null;
            setError(first || e?.message || "Não foi possível guardar o perfil.");
        } finally { setBusy(false); }
    };

    const title = editing ? `Editar perfil: ${editing.name}` : "Novo perfil";

    return (
        <Modal isOpen={target !== null} toggle={() => !busy && onClose()} centered size="lg" scrollable>
            <ModalHeader toggle={() => !busy && onClose()}>{title}</ModalHeader>
            <ModalBody>
                {error && <Alert color="danger" className="fs-13 py-2">{error}</Alert>}

                {step === "inicio" && (
                    <>
                        {sides.length > 1 && (
                            <div className="mb-3">
                                <Label className="form-label d-block">Para quem é o perfil</Label>
                                <div className="xp-seg flex-wrap" role="radiogroup" aria-label="Para quem é o perfil">
                                    {sides.map((s) => (
                                        <button key={s} type="button" role="radio" aria-checked={side === s} className={side === s ? "on" : ""} onClick={() => setSide(s)}>{SIDE_LABEL[s]}</button>
                                    ))}
                                </div>
                            </div>
                        )}
                        <p className="text-muted fs-13">Comece por uma sugestão e ajuste o que quiser, ou parta de um perfil vazio. Nada é imposto: no passo seguinte vê e muda cada área.</p>
                        <div className="vstack gap-2">
                            {suggestions.map((s) => (
                                <button key={s.id} type="button" className="btn btn-light text-start border p-3" onClick={() => startFrom(s)}>
                                    <span className="fw-semibold d-block text-body">{s.name}</span>
                                    <span className="text-muted fs-13 d-block">{s.description}</span>
                                    <span className="text-muted fs-12 d-block mt-1">
                                        {s.summary.filter((a) => a.actions.length > 0).map((a) => a.label).join(", ") || "Sem áreas"}
                                    </span>
                                </button>
                            ))}
                            <button type="button" className="btn btn-light text-start border p-3" onClick={() => startFrom(null)}>
                                <span className="fw-semibold d-block text-body">Perfil vazio</span>
                                <span className="text-muted fs-13 d-block">Sem nenhuma permissão: escolhe tudo à mão.</span>
                            </button>
                        </div>
                    </>
                )}

                {step === "permissoes" && (
                    <>
                        <div className="row g-3 mb-3">
                            <div className="col-md-6">
                                <Label htmlFor="pp-name" className="form-label">Nome do perfil</Label>
                                <Input id="pp-name" value={name} maxLength={120} onChange={(e) => setName(e.target.value)} placeholder="Por exemplo, Marketing" />
                            </div>
                            <div className="col-md-6">
                                <Label htmlFor="pp-desc" className="form-label">Descrição (opcional)</Label>
                                <Input id="pp-desc" value={description} maxLength={500} onChange={(e) => setDescription(e.target.value)} />
                            </div>
                        </div>
                        <p className="text-muted fs-13 mb-2">
                            {SIDE_LABEL[side]}{from ? `. A partir de "${from.name}".` : "."} Marque o que este perfil pode fazer em cada área.
                        </p>
                        <div className="vstack gap-2" data-testid="profile-areas">
                            {areas.map((a) => (
                                <div key={a.area} className="border rounded p-2 px-3">
                                    <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                        <span className="fw-medium">{a.label}</span>
                                        <div className="d-flex flex-wrap gap-3">
                                            {a.actions.filter((act) => allowed.has(`${a.area}.${act}`)).map((act) => {
                                                const key = `${a.area}.${act}`;
                                                return (
                                                    <div key={key} className="form-check mb-0">
                                                        <Input type="checkbox" className="form-check-input" id={`pp-${key}`} checked={selected.has(key) || BASE.includes(key)}
                                                            disabled={BASE.includes(key)} title={BASE.includes(key) ? "Sempre: quem trabalha na empresa vê-a." : undefined} onChange={() => toggle(key)} />
                                                        <Label className="form-check-label fs-13" htmlFor={`pp-${key}`}>{ACTION_LABEL[act]}{BASE.includes(key) ? " (sempre)" : ""}</Label>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                    <div className="text-muted fs-12 mt-1">{describeArea(a, selected)}</div>
                                </div>
                            ))}
                        </div>
                    </>
                )}

                {step === "rever" && preview && (
                    <>
                        <p className="fs-13 mb-2">Antes de gravar, as permissões efetivas de <strong>{name.trim()}</strong>:</p>
                        {preview.note && <Alert color="info" className="fs-13 py-2">{preview.note}</Alert>}
                        {preview.inactive_modules.length > 0 && (
                            <Alert color="warning" className="fs-13 py-2">Sem efeito por agora (o módulo não está ativo nesta empresa): {preview.inactive_modules.join(", ")}.</Alert>
                        )}
                        {preview.ignored.length > 0 && (
                            <Alert color="warning" className="fs-13 py-2">Ficam de fora (não se podem dar deste lado): {preview.ignored.join(", ")}.</Alert>
                        )}
                        <ul className="list-unstyled vstack gap-1 mb-0" data-testid="profile-preview">
                            {preview.summary.map((s: AreaSummary) => (
                                <li key={s.area} className="d-flex flex-wrap gap-2 fs-13">
                                    <span className="fw-medium" style={{ minWidth: 170 }}>{s.label}</span>
                                    <span className={s.actions.length ? "" : "text-muted"}>{s.text}</span>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={() => (step === "rever" ? setStep("permissoes") : step === "permissoes" && !editing && !(target && target !== "new" && "from" in target) ? setStep("inicio") : onClose())} disabled={busy}>
                    {step === "inicio" || (step === "permissoes" && (editing || (target && target !== "new" && "from" in target))) ? "Cancelar" : "Voltar"}
                </Button>
                {step === "permissoes" && (
                    <ReasonButton color="primary" onClick={() => void review()} disabled={busy} reason={!name.trim() ? "Indique o nome do perfil." : null}>
                        {busy ? <Spinner size="sm" /> : "Rever permissões"}
                    </ReasonButton>
                )}
                {step === "rever" && (
                    <Button color="primary" onClick={() => void save()} disabled={busy}>{busy ? <Spinner size="sm" /> : editing ? "Guardar perfil" : "Criar perfil"}</Button>
                )}
            </ModalFooter>
        </Modal>
    );
}
