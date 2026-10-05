import React, { useEffect, useMemo, useState } from "react";
import { Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import CreatableSelect from "react-select/creatable";
import { toast } from "react-toastify";
import { getBrandProfile, updateBrandProfile } from "helpers/laravel_helper";
import { IBrandProfile } from "common/models/blog.model";

/**
 * Perfil de marca simples (tom de voz, público, palavras a usar e a evitar, temas a evitar).
 * Entra no prompt do "Ajudar a escrever". Alterar: administrador ou equipa XPLENDOR.
 */
const readAuth = () => {
    try { return JSON.parse(sessionStorage.getItem("authUser") || "null") ?? {}; } catch { return {}; }
};
const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

type Props = { isOpen: boolean; toggle: () => void; companyId: number; onSaved?: (p: IBrandProfile) => void };

const EMPTY: IBrandProfile = { tone_of_voice: "", audience: "", words_to_use: [], words_to_avoid: [], topics_to_avoid: [], language: "pt-PT", updated_at: null };

const TagsInput = ({ value, onChange, placeholder, disabled }: { value: string[]; onChange: (v: string[]) => void; placeholder: string; disabled: boolean }) => (
    <CreatableSelect
        isMulti
        isDisabled={disabled}
        placeholder={placeholder}
        formatCreateLabel={(v) => `Acrescentar "${v}"`}
        noOptionsMessage={() => "Escreva e carregue em Enter"}
        value={value.map((v) => ({ label: v, value: v }))}
        onChange={(opts) => onChange((opts || []).map((o: any) => o.value))}
        onCreateOption={(v) => { const t = v.trim(); if (t && !value.includes(t)) onChange([...value, t]); }}
    />
);

const BrandProfileModal = ({ isOpen, toggle, companyId, onSaved }: Props) => {
    const auth = useMemo(readAuth, []);
    const canEdit = auth.role === "admin" || auth.role === "root" || !!auth.impersonating;
    const [form, setForm] = useState<IBrandProfile>(EMPTY);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!isOpen || !companyId) return;
        setLoading(true);
        getBrandProfile(companyId)
            .then((r: any) => setForm({ ...EMPTY, ...r.data, tone_of_voice: r.data.tone_of_voice ?? "", audience: r.data.audience ?? "" }))
            .catch(() => toast.error("Não foi possível carregar o perfil da marca."))
            .finally(() => setLoading(false));
    }, [isOpen, companyId]);

    const set = <K extends keyof IBrandProfile>(k: K, v: IBrandProfile[K]) => setForm((f) => ({ ...f, [k]: v }));

    const save = async () => {
        setSaving(true);
        try {
            const r: any = await updateBrandProfile(companyId, {
                tone_of_voice: form.tone_of_voice, audience: form.audience,
                words_to_use: form.words_to_use, words_to_avoid: form.words_to_avoid, topics_to_avoid: form.topics_to_avoid,
            });
            toast.success("Perfil da marca guardado.");
            onSaved?.(r.data);
            toggle();
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível guardar o perfil."));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal isOpen={isOpen} toggle={toggle} size="lg" centered>
            <ModalHeader toggle={toggle}>Perfil da marca</ModalHeader>
            <ModalBody>
                {loading ? <div className="text-center py-4"><Spinner size="sm" /></div> : (
                    <>
                        <p className="text-muted">
                            Estas indicações entram no "Ajudar a escrever". Quanto mais concretas, melhor o rascunho.
                            {!canEdit && " Só o administrador da empresa pode alterar o perfil."}
                        </p>
                        <div className="mb-3">
                            <Label>Tom de voz</Label>
                            <textarea className="form-control" rows={3} maxLength={2000} disabled={!canEdit} value={form.tone_of_voice ?? ""}
                                onChange={(e) => set("tone_of_voice", e.target.value)}
                                placeholder="Ex.: próximo e técnico, sem exageros; tratamos o cliente por você; frases curtas." />
                        </div>
                        <div className="mb-3">
                            <Label>Público (descrito pela empresa)</Label>
                            <textarea className="form-control" rows={3} maxLength={2000} disabled={!canEdit} value={form.audience ?? ""}
                                onChange={(e) => set("audience", e.target.value)}
                                placeholder="Ex.: famílias e reformados que viajam de autocaravana; muitos compram a primeira." />
                            <div className="form-text">Os dados medidos (GA4, Meta, vendas) entram à parte, só quando houver volume suficiente.</div>
                        </div>
                        <div className="mb-3">
                            <Label>Palavras a usar</Label>
                            <TagsInput value={form.words_to_use} onChange={(v) => set("words_to_use", v)} placeholder="Ex.: liberdade, estrada, conforto" disabled={!canEdit} />
                        </div>
                        <div className="mb-3">
                            <Label>Palavras a evitar</Label>
                            <TagsInput value={form.words_to_avoid} onChange={(v) => set("words_to_avoid", v)} placeholder="Ex.: barato, imperdível" disabled={!canEdit} />
                        </div>
                        <div>
                            <Label>Temas a evitar</Label>
                            <TagsInput value={form.topics_to_avoid} onChange={(v) => set("topics_to_avoid", v)} placeholder="Ex.: política, concorrentes" disabled={!canEdit} />
                        </div>
                    </>
                )}
            </ModalBody>
            <ModalFooter>
                <button type="button" className="btn btn-light" onClick={toggle}>Fechar</button>
                {canEdit && (
                    <button type="button" className="btn btn-primary" onClick={save} disabled={saving || loading}>
                        {saving && <Spinner size="sm" className="me-1" />}Guardar
                    </button>
                )}
            </ModalFooter>
        </Modal>
    );
};

export default BrandProfileModal;
