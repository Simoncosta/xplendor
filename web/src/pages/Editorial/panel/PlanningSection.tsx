import { useEffect, useMemo, useState } from "react";
import { Col, Input, Label, Row, Spinner } from "reactstrap";
import { Link } from "react-router-dom";
import { getBlogs } from "helpers/laravel_helper";
import { BLOG_STATUS_META } from "common/models/blog.model";
import { NETWORKS, Network, POST_CHANNEL_META, POST_FORMATS } from "common/models/editorialPost.model";
import { FormatTable, crossCheck } from "common/models/editorialWorkflow.model";
import XSelect, { XOption } from "Components/Common/Select";
import ActionsMenu from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";

/**
 * Planeamento da publicação (também o ecrã de criar): tema, data e hora, onde publicar
 * (Instagram e/ou Facebook, ou o Site) e o formato em cada rede, validado pela regra única
 * dos formatos (o servidor confirma). Ao criar, só o essencial; o resto em "Mais detalhes".
 */

export type PlanValues = {
    title: string; publish_date: string; publish_time: string;
    site: boolean; networks: Partial<Record<Network, string>>; order: Network[];
    format: string; pillar: string; link: string; keyword: string; blog_id: string;
};

export const emptyPlan = (date: string): PlanValues => ({
    title: "", publish_date: date, publish_time: "", site: false, networks: { instagram: "" }, order: ["instagram"],
    format: POST_FORMATS[0], pillar: "", link: "", keyword: "", blog_id: "",
});

/** O corpo do pedido (criar ou editar). */
export function planPayload(v: PlanValues): Record<string, unknown> {
    const body: Record<string, unknown> = {
        title: v.title.trim(), publish_date: v.publish_date, keyword: v.keyword.trim() || null, pillar: v.pillar || null,
        channel: v.site ? "site" : "social", format: v.site ? "Artigo" : v.format,
        publish_time: v.site ? null : (v.publish_time || null),
        blog_id: v.site && v.blog_id ? Number(v.blog_id) : null,
    };
    if (!v.site) body.networks = Object.fromEntries(v.order.map((n) => [n, v.networks[n] || null]));
    if (v.link.startsWith("a:")) body.anchor_id = Number(v.link.slice(2));
    else if (v.link.startsWith("o:")) body.own_anchor_id = Number(v.link.slice(2));
    return body;
}

type Props = {
    companyId: number;
    values: PlanValues;
    onChange: (v: PlanValues) => void;
    creating: boolean;
    disabled: boolean;
    lockedNetworks: boolean;
    formats: FormatTable | null;
    anchors: XOption[];
    pillars: string[];
    range?: { from: string; to: string } | null;
    busy: boolean;
    onSubmit: () => void;
    onDelete?: () => void;
    writeArticleUrl?: string | null;
    errors?: string[];
};

export default function PlanningSection({ companyId, values: v, onChange, creating, disabled, lockedNetworks, formats, anchors, pillars, range, busy, onSubmit, onDelete, writeArticleUrl, errors }: Props) {
    const [more, setMore] = useState(!creating);
    const [blogs, setBlogs] = useState<XOption[]>([]);
    const set = (patch: Partial<PlanValues>) => onChange({ ...v, ...patch });

    useEffect(() => {
        if (!v.site || !companyId) return;
        getBlogs(companyId, { perPage: 100 }).then((r: any) => setBlogs([{ value: "", label: "Ainda sem artigo" },
            ...(r?.data?.page?.data ?? []).map((b: any) => ({ value: String(b.id), label: `${b.title} (${BLOG_STATUS_META[b.status as keyof typeof BLOG_STATUS_META]?.label ?? b.status})` }))]))
            .catch(() => setBlogs([{ value: "", label: "Ainda sem artigo" }]));
    }, [v.site, companyId]);

    const toggleNetwork = (n: Network) => {
        if (v.site || lockedNetworks) {
            set({ site: false, networks: { [n]: "" }, order: [n] });
            return;
        }
        if (v.order.includes(n)) {
            if (v.order.length === 1) return; // pelo menos uma rede
            const networks = { ...v.networks };
            delete networks[n];
            set({ networks, order: v.order.filter((x) => x !== n) });
        } else {
            // A outra rede sugere o formato mais próximo (regra única).
            const other = v.order[0];
            const suggested = other && v.networks[other] && formats ? formats.suggest[v.networks[other] as string] ?? "" : "";
            const fits = formats?.formats[n]?.some((f) => f.value === suggested);
            set({ networks: { ...v.networks, [n]: fits ? suggested : "" }, order: NETWORKS.filter((x) => x === n || v.order.includes(x)) });
        }
    };

    const check = useMemo(() => crossCheck(formats, v.networks), [formats, v.networks]);
    const pillarOptions: XOption[] = [{ value: "", label: "Sem pilar" }, ...[...pillars, ...(v.pillar && !pillars.includes(v.pillar) ? [v.pillar] : [])].map((p) => ({ value: p, label: p }))];
    const chip = (active: boolean, onClick: () => void, icon: string, label: string, id: string) => (
        <button type="button" id={id} role="checkbox" aria-checked={active} disabled={disabled} onClick={onClick}
            className={`btn btn-sm rounded-pill d-inline-flex align-items-center gap-1 btn-outline-primary ${active ? "active" : ""}`}>
            <i className={active ? "ri-checkbox-fill" : "ri-checkbox-blank-line"} /><i className={icon} />{label}
        </button>
    );

    return (
        <form onSubmit={(e) => { e.preventDefault(); onSubmit(); }}>
            <Label for="pp-title" className="mb-1">Tema</Label>
            <Input id="pp-title" className="mb-3" value={v.title} maxLength={255} disabled={disabled} autoFocus={creating}
                onChange={(e) => set({ title: e.target.value })} placeholder="Por exemplo, Menu de São Martinho" />

            <Row className="g-2 mb-3">
                <Col xs={v.site ? 12 : 6}>
                    <Label for="pp-date" className="mb-1">Data</Label>
                    <Input id="pp-date" type="date" value={v.publish_date} min={range?.from} max={range?.to} disabled={disabled} onChange={(e) => set({ publish_date: e.target.value })} />
                </Col>
                {!v.site && (
                    <Col xs={6}>
                        <Label for="pp-time" className="mb-1">Hora <span className="text-muted fw-normal">(opcional)</span></Label>
                        <Input id="pp-time" type="time" value={v.publish_time} disabled={disabled} onChange={(e) => set({ publish_time: e.target.value })} />
                    </Col>
                )}
            </Row>

            <Label className="mb-1 d-block">Onde publicar</Label>
            <div className="d-flex flex-wrap gap-2 mb-2">
                {NETWORKS.map((n) => chip(!v.site && v.order.includes(n), () => toggleNetwork(n), POST_CHANNEL_META[n].icon, POST_CHANNEL_META[n].label, `pp-net-${n}`))}
                {chip(v.site, () => set({ site: true }), POST_CHANNEL_META.site.icon, "Site (artigo do blog)", "pp-net-site")}
            </div>
            {lockedNetworks && <p className="text-muted fs-12 mb-2">Depois de publicada, as redes não mudam. Para uma rede que não vai sair, use "Não publicar nesta rede" em Publicação e Análise.</p>}

            {v.site ? (
                <div className="mb-3">
                    <Label for="pp-blog" className="mb-1">Artigo do blog</Label>
                    <XSelect id="pp-blog" options={blogs} value={v.blog_id} onChange={(x) => set({ blog_id: x })} disabled={disabled} />
                    <div className="form-text">O estado no calendário vem do artigo (rascunho, em revisão, agendado, publicado).</div>
                    {!v.blog_id && writeArticleUrl && <Link to={writeArticleUrl} className="btn btn-sm btn-outline-primary mt-2"><i className="ri-quill-pen-line me-1" />Escrever artigo</Link>}
                </div>
            ) : (
                <>
                    <Row className="g-2 mb-1">
                        {v.order.map((n) => (
                            <Col xs={12} sm={v.order.length > 1 ? 6 : 12} key={n}>
                                <Label for={`pp-format-${n}`} className="mb-1">Formato no {POST_CHANNEL_META[n].label}</Label>
                                <XSelect id={`pp-format-${n}`} disabled={disabled} placeholder="Por definir"
                                    options={[{ value: "", label: "Por definir" }, ...(formats?.formats[n] ?? [])]}
                                    value={v.networks[n] ?? ""} onChange={(x) => set({ networks: { ...v.networks, [n]: x } })} />
                            </Col>
                        ))}
                    </Row>
                    {check.errors.map((m) => <div key={m} className="text-danger fs-12"><i className="ri-close-circle-line me-1" />{m}</div>)}
                    {check.warnings.map((m) => <div key={m} className="text-warning-emphasis fs-12"><i className="ri-error-warning-line me-1" />{m}</div>)}
                </>
            )}

            <button type="button" className="btn btn-link btn-sm px-0 mt-2" onClick={() => setMore((m) => !m)} aria-expanded={more}>
                <i className={more ? "ri-subtract-line" : "ri-add-line"} /> {more ? "Menos detalhes" : "Mais detalhes (tipo de conteúdo, pilar, âncora, palavra-chave)"}
            </button>
            {more && (
                <Row className="g-2 mt-1">
                    {!v.site && (
                        <Col sm={6}>
                            <Label for="pp-type" className="mb-1">Tipo de conteúdo</Label>
                            <XSelect id="pp-type" options={POST_FORMATS.map((f) => ({ value: f, label: f }))} value={v.format} onChange={(x) => set({ format: x })} disabled={disabled} />
                        </Col>
                    )}
                    <Col sm={6}>
                        <Label for="pp-pillar" className="mb-1">Pilar</Label>
                        <XSelect id="pp-pillar" options={pillarOptions} value={v.pillar} onChange={(x) => set({ pillar: x })} disabled={disabled} />
                    </Col>
                    <Col sm={6}>
                        <Label for="pp-anchor" className="mb-1">Âncora <span className="text-muted fw-normal">(opcional)</span></Label>
                        <XSelect id="pp-anchor" options={anchors} value={v.link} onChange={(x) => set({ link: x })} disabled={disabled} searchable />
                    </Col>
                    <Col sm={6}>
                        <Label for="pp-keyword" className="mb-1">Palavra-chave</Label>
                        <Input id="pp-keyword" value={v.keyword} maxLength={255} disabled={disabled} onChange={(e) => set({ keyword: e.target.value })} placeholder="Por exemplo, natal" />
                    </Col>
                </Row>
            )}

            {errors && errors.length > 0 && <div className="alert alert-danger fs-13 py-2 mt-3 mb-0">{errors.map((e) => <div key={e}>{e}</div>)}</div>}

            {!disabled && (
                <div className="d-flex gap-2 justify-content-end mt-3">
                    {onDelete && <ActionsMenu className="me-auto" label="Mais ações da publicação" disabled={busy} items={[{ label: "Apagar publicação", icon: "ri-delete-bin-line", danger: true, onClick: onDelete }]} />}
                    <ReasonButton color="primary" type="submit" disabled={busy}
                        reason={!v.title.trim() ? "Indique o título." : check.errors.length > 0 ? "Corrija os formatos assinalados." : null}>
                        {busy ? <Spinner size="sm" /> : creating ? "Criar publicação" : "Guardar planeamento"}
                    </ReasonButton>
                </div>
            )}
        </form>
    );
}
