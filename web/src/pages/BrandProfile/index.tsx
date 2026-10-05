import { useEffect, useMemo, useState } from "react";
import { Card, CardBody, CardHeader, Col, Container, Label, Row, Spinner } from "reactstrap";
import Select from "react-select";
import CreatableSelect from "react-select/creatable";
import { toast, ToastContainer } from "react-toastify";
import { getBrandProfile, getLatestAiRequest, updateBrandProfile } from "helpers/laravel_helper";
import { useSearchParams } from "react-router-dom";
import { reactSelectTheme } from "helpers/reactSelectStyles";
import type { BrandPillar, EmojiPolicy, IBrandProfile } from "common/models/blog.model";
import BrandProfileSuggestModal from "./BrandProfileSuggestModal";
import FollowersCard from "./FollowersCard";

/**
 * Perfil da Marca (página própria, fora do Blog). Alimenta a IA (blog, sugestões de
 * perfil e de criativos). Os campos seguem os nomes previstos para brand_profiles no
 * plano Social (F1). Alterar: administrador da empresa e equipa XPLENDOR (o backend
 * decide e devolve can_edit). Inclui os seguidores atuais e o crescimento.
 */

const EMPTY: IBrandProfile = {
    tone_of_voice: "", audience: "", words_to_use: [], words_to_avoid: [], topics_to_avoid: [],
    pillars: [], hashtags_default: [], cta_default: "", emoji_policy: null, notes: "",
    language: "pt-PT", updated_at: null,
};

const EMOJI_OPTIONS: { value: EmojiPolicy; label: string }[] = [
    { value: "none", label: "Sem emojis" },
    { value: "light", label: "Poucos emojis (com moderação)" },
    { value: "free", label: "Emojis à vontade" },
];

const MAX_PILLARS = 8;

const companyIdFromSession = () => {
    try { return Number(JSON.parse(sessionStorage.getItem("authUser") || "null")?.company_id || 0); } catch { return 0; }
};

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

const TagsInput = ({ value, onChange, placeholder, disabled, id }: { value: string[]; onChange: (v: string[]) => void; placeholder: string; disabled: boolean; id: string }) => (
    <CreatableSelect
        inputId={id}
        isMulti
        isDisabled={disabled}
        placeholder={placeholder}
        styles={reactSelectTheme}
        menuPortalTarget={document.body}
        formatCreateLabel={(v) => `Acrescentar "${v}"`}
        noOptionsMessage={() => "Escreva e carregue em Enter"}
        value={value.map((v) => ({ label: v, value: v }))}
        onChange={(opts) => onChange((opts || []).map((o: any) => o.value))}
        onCreateOption={(v) => { const t = v.trim(); if (t && !value.includes(t)) onChange([...value, t]); }}
    />
);

export default function BrandProfilePage() {
    document.title = "Perfil da Marca | Xplendor";
    const companyId = useMemo(companyIdFromSession, []);
    const [form, setForm] = useState<IBrandProfile>(EMPTY);
    const [canEdit, setCanEdit] = useState(false);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [suggestOpen, setSuggestOpen] = useState(false);
    // Sugestão à espera (pedida antes de sair da página) e ligação vinda do sino (?suggestion=).
    const [waiting, setWaiting] = useState<{ status: string } | null>(null);
    const [searchParams, setSearchParams] = useSearchParams();

    useEffect(() => {
        if (!companyId || !canEdit) return;
        getLatestAiRequest(companyId, { mode: "brand_profile" }).then((r: any) => setWaiting(r?.data ?? null)).catch(() => setWaiting(null));
        if (searchParams.get("suggestion")) {
            setSuggestOpen(true);
            const next = new URLSearchParams(searchParams);
            next.delete("suggestion");
            setSearchParams(next, { replace: true });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [companyId, canEdit, suggestOpen]);

    useEffect(() => {
        if (!companyId) return;
        setLoading(true);
        getBrandProfile(companyId)
            .then((r: any) => {
                const d = r?.data ?? {};
                setForm({ ...EMPTY, ...d, tone_of_voice: d.tone_of_voice ?? "", audience: d.audience ?? "", cta_default: d.cta_default ?? "", notes: d.notes ?? "" });
                setCanEdit(!!d.can_edit);
            })
            .catch(() => toast.error("Não foi possível carregar o perfil da marca."))
            .finally(() => setLoading(false));
    }, [companyId]);

    const set = <K extends keyof IBrandProfile>(k: K, v: IBrandProfile[K]) => setForm((f) => ({ ...f, [k]: v }));
    const setPillar = (i: number, patch: Partial<BrandPillar>) =>
        set("pillars", form.pillars.map((p, j) => (j === i ? { ...p, ...patch } : p)));
    const movePillar = (i: number, delta: -1 | 1) => {
        const next = [...form.pillars];
        const j = i + delta;
        if (j < 0 || j >= next.length) return;
        [next[i], next[j]] = [next[j], next[i]];
        set("pillars", next);
    };

    const save = async () => {
        setSaving(true);
        try {
            const r: any = await updateBrandProfile(companyId, {
                tone_of_voice: form.tone_of_voice, audience: form.audience,
                words_to_use: form.words_to_use, words_to_avoid: form.words_to_avoid, topics_to_avoid: form.topics_to_avoid,
                pillars: form.pillars.filter((p) => p.name.trim() !== ""),
                hashtags_default: form.hashtags_default,
                cta_default: form.cta_default, emoji_policy: form.emoji_policy, notes: form.notes,
            });
            const d = r?.data ?? {};
            setForm({ ...EMPTY, ...d, tone_of_voice: d.tone_of_voice ?? "", audience: d.audience ?? "", cta_default: d.cta_default ?? "", notes: d.notes ?? "" });
            toast.success("Perfil da marca guardado.");
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível guardar o perfil."));
        } finally {
            setSaving(false);
        }
    };

    const disabled = !canEdit || loading;

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row>
                    <Col xs={12}>
                        <div className="page-title-box d-sm-flex align-items-center justify-content-between gap-2">
                            <div>
                                <h4 className="mb-sm-0">Perfil da Marca</h4>
                                <p className="text-muted fs-13 mb-0 mt-1">Como a marca fala e para quem. Entra nos rascunhos e nas sugestões feitas com IA.</p>
                            </div>
                            {canEdit && (
                                <div className="d-flex flex-wrap align-items-center gap-2 mt-2 mt-sm-0">
                                    {waiting && !suggestOpen && (
                                        <button type="button" className={`btn btn-sm ${waiting.status === "done" ? "btn-soft-success" : waiting.status === "error" ? "btn-soft-danger" : "btn-soft-secondary"}`} onClick={() => setSuggestOpen(true)}>
                                            <i className={`${waiting.status === "done" ? "ri-checkbox-circle-line" : waiting.status === "error" ? "ri-error-warning-line" : "ri-time-line"} me-1`} />
                                            {waiting.status === "done" ? "Sugestão pronta: ver" : waiting.status === "error" ? "A sugestão falhou: ver" : "Sugestão em preparação"}
                                        </button>
                                    )}
                                    <button type="button" className="btn btn-soft-primary" onClick={() => setSuggestOpen(true)} disabled={loading}>
                                        <i className="ri-magic-line me-1" />Sugerir perfil
                                    </button>
                                    <button type="button" className="btn btn-primary" onClick={save} disabled={saving || loading}>
                                        {saving ? <Spinner size="sm" className="me-1" /> : <i className="ri-save-3-line me-1" />}Guardar
                                    </button>
                                </div>
                            )}
                        </div>
                    </Col>
                </Row>

                {!loading && !canEdit && (
                    <div className="alert alert-info fs-13">Só o administrador da empresa pode alterar o perfil da marca.</div>
                )}

                {loading ? (
                    <div className="text-center py-5"><Spinner color="primary" /></div>
                ) : (
                    <>
                        <Row className="g-3">
                            <Col xl={6}>
                                <Card className="mb-3">
                                    <CardHeader><h5 className="card-title mb-0">Voz da marca</h5></CardHeader>
                                    <CardBody>
                                        <div className="mb-3">
                                            <Label for="bp-tone">Tom de voz</Label>
                                            <textarea id="bp-tone" className="form-control" rows={3} maxLength={2000} disabled={disabled} value={form.tone_of_voice ?? ""}
                                                onChange={(e) => set("tone_of_voice", e.target.value)}
                                                placeholder="Ex.: próximo e técnico, sem exageros; tratamos o cliente por você; frases curtas." />
                                        </div>
                                        <div className="mb-3">
                                            <Label for="bp-audience">Público (descrito pela empresa)</Label>
                                            <textarea id="bp-audience" className="form-control" rows={3} maxLength={2000} disabled={disabled} value={form.audience ?? ""}
                                                onChange={(e) => set("audience", e.target.value)}
                                                placeholder="Ex.: famílias e reformados que viajam de autocaravana; muitos compram a primeira." />
                                            <div className="form-text">Os dados medidos (GA4, Meta, vendas) entram à parte, só quando houver volume suficiente.</div>
                                        </div>
                                        <div>
                                            <Label for="bp-emoji">Emojis</Label>
                                            <Select
                                                inputId="bp-emoji"
                                                styles={reactSelectTheme}
                                                menuPortalTarget={document.body}
                                                options={EMOJI_OPTIONS}
                                                isClearable
                                                isSearchable={false}
                                                isDisabled={disabled}
                                                placeholder="Sem preferência"
                                                value={EMOJI_OPTIONS.find((o) => o.value === form.emoji_policy) ?? null}
                                                onChange={(o: any) => set("emoji_policy", o?.value ?? null)}
                                            />
                                        </div>
                                    </CardBody>
                                </Card>

                                <Card className="mb-3">
                                    <CardHeader><h5 className="card-title mb-0">Chamada à ação e notas</h5></CardHeader>
                                    <CardBody>
                                        <div className="mb-3">
                                            <Label for="bp-cta">Chamada à ação habitual</Label>
                                            <input id="bp-cta" className="form-control" maxLength={300} disabled={disabled} value={form.cta_default ?? ""}
                                                onChange={(e) => set("cta_default", e.target.value)} placeholder="Ex.: Marque a sua visita pelo WhatsApp." />
                                        </div>
                                        <div>
                                            <Label for="bp-notes">Notas</Label>
                                            <textarea id="bp-notes" className="form-control" rows={3} maxLength={2000} disabled={disabled} value={form.notes ?? ""}
                                                onChange={(e) => set("notes", e.target.value)} placeholder="Ex.: nunca indicar preços nas redes; referir sempre a garantia." />
                                        </div>
                                    </CardBody>
                                </Card>
                            </Col>

                            <Col xl={6}>
                                <Card className="mb-3">
                                    <CardHeader className="d-flex align-items-center justify-content-between gap-2">
                                        <div>
                                            <h5 className="card-title mb-1">Pilares de conteúdo</h5>
                                            <p className="text-muted fs-12 mb-0">Os grandes temas da marca. A ordem é a prioridade.</p>
                                        </div>
                                        {canEdit && form.pillars.length < MAX_PILLARS && (
                                            <button type="button" className="btn btn-soft-primary btn-sm" onClick={() => set("pillars", [...form.pillars, { name: "", description: "" }])}>
                                                <i className="ri-add-line me-1" />Acrescentar
                                            </button>
                                        )}
                                    </CardHeader>
                                    <CardBody>
                                        {form.pillars.length === 0 ? (
                                            <p className="text-muted fs-13 mb-0">Ainda sem pilares. Ex.: bastidores, dicas de utilização, novidades do stock.</p>
                                        ) : (
                                            <div className="vstack gap-2">
                                                {form.pillars.map((p, i) => (
                                                    <div key={i} className="border rounded p-2">
                                                        <div className="d-flex gap-2 align-items-start">
                                                            <span className="badge bg-primary-subtle text-primary mt-2">{i + 1}</span>
                                                            <div className="flex-grow-1">
                                                                <input className="form-control form-control-sm mb-1" maxLength={60} disabled={disabled} value={p.name}
                                                                    aria-label={`Nome do pilar ${i + 1}`} placeholder="Nome do pilar"
                                                                    onChange={(e) => setPillar(i, { name: e.target.value })} />
                                                                <input className="form-control form-control-sm" maxLength={300} disabled={disabled} value={p.description ?? ""}
                                                                    aria-label={`Descrição do pilar ${i + 1}`} placeholder="Descrição (opcional)"
                                                                    onChange={(e) => setPillar(i, { description: e.target.value })} />
                                                            </div>
                                                            {canEdit && (
                                                                <div className="d-flex flex-column gap-1">
                                                                    <button type="button" className="btn btn-light btn-sm py-0" title="Subir" aria-label="Subir" disabled={i === 0} onClick={() => movePillar(i, -1)}><i className="ri-arrow-up-s-line" /></button>
                                                                    <button type="button" className="btn btn-light btn-sm py-0" title="Descer" aria-label="Descer" disabled={i === form.pillars.length - 1} onClick={() => movePillar(i, 1)}><i className="ri-arrow-down-s-line" /></button>
                                                                    <button type="button" className="btn btn-soft-danger btn-sm py-0" title="Remover" aria-label="Remover" onClick={() => set("pillars", form.pillars.filter((_, j) => j !== i))}><i className="ri-delete-bin-line" /></button>
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </CardBody>
                                </Card>

                                <Card className="mb-3">
                                    <CardHeader><h5 className="card-title mb-0">Palavras, temas e hashtags</h5></CardHeader>
                                    <CardBody>
                                        <div className="mb-3">
                                            <Label for="bp-use">Palavras a usar</Label>
                                            <TagsInput id="bp-use" value={form.words_to_use} onChange={(v) => set("words_to_use", v)} placeholder="Ex.: liberdade, estrada, conforto" disabled={disabled} />
                                        </div>
                                        <div className="mb-3">
                                            <Label for="bp-avoid">Palavras a evitar</Label>
                                            <TagsInput id="bp-avoid" value={form.words_to_avoid} onChange={(v) => set("words_to_avoid", v)} placeholder="Ex.: barato, imperdível" disabled={disabled} />
                                        </div>
                                        <div className="mb-3">
                                            <Label for="bp-topics">Temas a evitar</Label>
                                            <TagsInput id="bp-topics" value={form.topics_to_avoid} onChange={(v) => set("topics_to_avoid", v)} placeholder="Ex.: política, concorrentes" disabled={disabled} />
                                        </div>
                                        <div>
                                            <Label for="bp-hashtags">Hashtags habituais</Label>
                                            <TagsInput id="bp-hashtags" value={form.hashtags_default} onChange={(v) => set("hashtags_default", v)} placeholder="Ex.: #autocaravanas, #portugal" disabled={disabled} />
                                            <div className="form-text">Uma hashtag é uma só palavra; o "#" é acrescentado ao guardar.</div>
                                        </div>
                                    </CardBody>
                                </Card>
                            </Col>
                        </Row>

                        {companyId > 0 && <FollowersCard companyId={companyId} />}
                        {canEdit && companyId > 0 && (
                            <BrandProfileSuggestModal
                                isOpen={suggestOpen}
                                toggle={() => setSuggestOpen(false)}
                                companyId={companyId}
                                current={form}
                                onApply={(patch) => setForm((f) => ({ ...f, ...patch }))}
                            />
                        )}
                        <div className="pb-5" />
                    </>
                )}
            </Container>
        </div>
    );
}
