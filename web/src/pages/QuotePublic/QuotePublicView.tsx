import { useMemo, useState } from "react";
import logo from "./xplendor-x.png";
import "./quote-public.css";
import {
    BUCKETS, Bucket, QuotePublicData, QuotePublicLine, computeSelection, discountLabel, lineDiscount, linePrice, lineQuantity, money,
} from "common/models/quotePublic.model";

/**
 * A proposta, com o aspeto do PDF, e as respostas do cliente (aceitar com as linhas
 * opcionais escolhidas, recusar, pedir alterações). Só apresenta: os pedidos ficam com a
 * página (ou com a pré-visualização da equipa, que nunca responde).
 */

export type AcceptInput = { name: string; email: string; terms_accepted: boolean; optional_keys: number[] };
export type ActionResult = { ok: true } | { ok: false; message: string; fields?: Record<string, string> };

type Props = {
    data: QuotePublicData;
    pdfHref?: string;
    onDownloadPdf?: () => void;
    onAccept?: (input: AcceptInput) => Promise<ActionResult>;
    onRefuse?: (reason: string) => Promise<ActionResult>;
    onRequestChanges?: (message: string) => Promise<ActionResult>;
};

type Sheet = null | "accept" | "refuse" | "changes";

const longDateTime = (iso: string) =>
    new Date(iso).toLocaleString("pt-PT", { day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit" }).replace(",", " às");

export function QuoteHeader({ data }: { data: QuotePublicData }) {
    return (
        <div className="qp-head">
            <div className="qp-brand"><img src={logo} alt="" /><span>{data.document.legal.brand}</span></div>
            <div className="qp-meta">
                <div className="qp-kind">ORÇAMENTO</div>
                <div className="qp-number">{data.document.number}</div>
                <div>{data.document.version_label}</div>
            </div>
        </div>
    );
}

export function QuoteContact({ data, intro }: { data: Pick<QuotePublicData, "contact">; intro?: string }) {
    const c = data.contact;
    return (
        <div className="qp-contact">
            {intro && <p className="qp-muted" style={{ marginBottom: 6 }}>{intro}</p>}
            {c.phone && <a href={`tel:${c.phone.replace(/\s+/g, "")}`}>{c.phone}</a>}
            {c.email && <a href={`mailto:${c.email}`}>{c.email}</a>}
            {c.website && <a href={`https://${c.website.replace(/^https?:\/\//, "")}`} target="_blank" rel="noopener noreferrer">{c.website}</a>}
        </div>
    );
}

export function QuoteFooter({ data }: { data: QuotePublicData }) {
    const legal = data.document.legal;
    return (
        <div className="qp-foot">
            <div>{legal.brand_line}</div>
            <div>{legal.contact_line}</div>
            {legal.socials?.length > 0 && (
                <div className="qp-socials">{legal.socials.map((s) => <a key={s.url} href={s.url} target="_blank" rel="noopener noreferrer">{s.title}</a>)}</div>
            )}
        </div>
    );
}

export default function QuotePublicView({ data, pdfHref, onDownloadPdf, onAccept, onRefuse, onRequestChanges }: Props) {
    const optionalKeys = useMemo(() => data.lines.filter((l) => l.is_optional).map((l) => l.key), [data.lines]);
    // Por omissão, todas as linhas opcionais ficam incluídas (a proposta completa).
    const [included, setIncluded] = useState<Set<number>>(() => new Set(optionalKeys));
    const [sheet, setSheet] = useState<Sheet>(null);
    const [form, setForm] = useState({ name: "", email: "", terms: false, reason: "", message: "" });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [sheetError, setSheetError] = useState<string | null>(null);
    const [changesSent, setChangesSent] = useState(false);

    const accepted = data.state === "accepted" && data.acceptance;
    const acceptedKeys = useMemo(() => new Set(data.acceptance?.accepted_keys ?? []), [data.acceptance]);
    const selection = useMemo(
        () => computeSelection(data.lines, data.global_discount, accepted ? acceptedKeys : included),
        [data.lines, data.global_discount, included, accepted, acceptedKeys],
    );
    const interactive = data.can_respond && data.has_optional;
    const respondable = data.can_respond && !!onAccept;

    const toggle = (key: number, on: boolean) => setIncluded((prev) => {
        const next = new Set(prev);
        if (on) next.add(key); else next.delete(key);
        return next;
    });

    const open = (s: Sheet) => { setSheet(s); setErrors({}); setSheetError(null); };
    const run = async (fn: () => Promise<ActionResult>, after?: () => void) => {
        setBusy(true);
        setErrors({});
        setSheetError(null);
        const r = await fn();
        setBusy(false);
        if (r.ok) {
            setSheet(null);
            after?.();
        } else {
            setErrors(r.fields ?? {});
            setSheetError(r.fields && Object.keys(r.fields).length ? null : r.message);
        }
    };

    const pdfButton = (pdfHref || onDownloadPdf) && (
        pdfHref
            ? <a className="qp-btn is-block" href={pdfHref} target="_blank" rel="noopener noreferrer">Descarregar o PDF</a>
            : <button type="button" className="qp-btn is-block" onClick={onDownloadPdf}>Descarregar o PDF</button>
    );

    // ── Expirado: mensagem, contacto e PDF ─────────────────────────────────
    if (data.state === "expired") {
        return (
            <div className="qp">
                <div className="qp-paper">
                    <QuoteHeader data={data} />
                    <div className="qp-result">
                        <div className="qp-result-icon is-grey" aria-hidden>⏱</div>
                        <h1>Este orçamento expirou</h1>
                        <p>Era válido até {data.document.valid_until}. Fale connosco para receber uma proposta atualizada.</p>
                        <QuoteContact data={data} />
                    </div>
                    <div className="qp-pdf">{pdfButton}</div>
                </div>
                <QuoteFooter data={data} />
            </div>
        );
    }

    // ── Aceite: ecrã de sucesso ─────────────────────────────────────────────
    if (accepted && data.acceptance) {
        const a = data.acceptance;
        const totals = a.buckets ?? selection.buckets;
        return (
            <div className="qp">
                <div className="qp-paper">
                    <QuoteHeader data={data} />
                    <div className="qp-result">
                        <div className="qp-result-icon is-green" aria-hidden>✓</div>
                        <h1>Orçamento aceite</h1>
                        <p>Obrigado, {a.name}. A aceitação ficou registada a {longDateTime(a.accepted_at)}.</p>
                        <p>A equipa {data.contact.brand} vai entrar em contacto para combinar o arranque.</p>
                        <div className="qp-result-list">
                            <strong>Serviços aceites</strong>
                            <ul>{data.lines.filter((l) => acceptedKeys.has(l.key)).map((l) => <li key={l.key}>{l.name}</li>)}</ul>
                            {totals.monthly.count > 0 && <div>Total mensal: <strong>{money(totals.monthly.total)}/mês</strong></div>}
                            {totals.one_off.count > 0 && <div>Total valor único: <strong>{money(totals.one_off.total)}</strong></div>}
                            {a.discount && !a.discount.applies && a.discount.reason && <div className="qp-dropped">{a.discount.reason}</div>}
                            <div className="qp-muted" style={{ fontSize: 13, marginTop: 6 }}>{data.document.vat_note}</div>
                        </div>
                        <QuoteContact data={data} intro="Alguma dúvida? Fale connosco:" />
                    </div>
                    <div className="qp-pdf">{pdfButton}</div>
                </div>
                <QuoteFooter data={data} />
            </div>
        );
    }

    // ── A proposta ──────────────────────────────────────────────────────────
    const notice = data.preview
        ? null
        : data.state === "superseded"
            ? { tone: "is-amber", title: "Existe uma versão mais recente deste orçamento.", text: "Esta versão já não pode ser aceite. Peça o link da versão atual à equipa." }
            : data.state === "under_revision"
                ? { tone: "is-amber", title: "Este orçamento está a ser revisto pela equipa.", text: "Vai receber uma nova versão. Esta já não pode ser aceite." }
                : data.state === "refused"
                    ? { tone: "is-grey", title: "Este orçamento foi recusado.", text: "Obrigado por nos ter respondido. Se mudar de ideias, fale connosco." }
                    : changesSent || data.changes_requested
                        ? { tone: "is-green", title: "O seu pedido de alterações foi enviado à equipa.", text: "Vai receber uma nova versão. Entretanto, ainda pode aceitar esta, se preferir." }
                        : null;

    const linesOf = (bucket: Bucket) => data.lines.filter((l) => (l.billing_type === "monthly") === (bucket === "monthly"));
    const isOut = (l: QuotePublicLine) => l.is_optional && !(accepted ? acceptedKeys : included).has(l.key);
    const chosenLines = selection.selected;

    return (
        <div className="qp">
            <div className="qp-paper">
                <QuoteHeader data={data} />
                {data.preview && <span className="qp-preview-badge">Pré-visualização da equipa: não conta como abertura</span>}
                {notice && <div className={`qp-notice ${notice.tone}`}><strong>{notice.title}</strong>{notice.text}</div>}

                <div className="qp-parties">
                    <div className="qp-party">
                        <div className="qp-label">Cliente</div>
                        <div className="qp-party-name">{data.document.customer.name}</div>
                        {data.document.customer.lines.map((l) => <div key={l} className="qp-muted">{l}</div>)}
                    </div>
                    <div className="qp-party">
                        <div className="qp-label">Datas</div>
                        <dl className="qp-dates">
                            <dt>Data do orçamento</dt><dd>{data.document.issued_at}</dd>
                            <dt>Válido até</dt><dd>{data.document.valid_until}</dd>
                            {data.document.minimum_contract && <><dt>Contrato mínimo</dt><dd>{data.document.minimum_contract}</dd></>}
                        </dl>
                    </div>
                </div>

                {(data.document.title_text || data.document.intro) && (
                    <div className="qp-intro">
                        {data.document.title_text && <h1>{data.document.title_text}</h1>}
                        {data.document.intro && <p>{data.document.intro}</p>}
                    </div>
                )}

                {interactive && (
                    <div className="qp-notice is-grey">
                        <strong>Pode escolher os serviços opcionais.</strong>
                        Desmarque os que não quer incluir; os totais atualizam de imediato.
                    </div>
                )}

                {BUCKETS.map((b) => {
                    const lines = linesOf(b.key);
                    if (lines.length === 0) return null;
                    const t = selection.buckets[b.key];
                    return (
                        <section className="qp-section" key={b.key}>
                            <h2 className="qp-section-title">{b.title} <span>({b.subtitle})</span></h2>
                            {lines.map((l) => {
                                const out = isOut(l);
                                return (
                                    <div className={`qp-line${out ? " is-out" : ""}`} key={l.key}>
                                        {interactive && l.is_optional ? (
                                            <input type="checkbox" className="qp-check" checked={!out} aria-label={`Incluir ${l.name}`}
                                                onChange={(e) => toggle(l.key, e.target.checked)} />
                                        ) : <span className="qp-check-spacer" />}
                                        <div>
                                            <div className="qp-line-name">{l.name}{l.is_optional && <span className="qp-tag">Opcional</span>}</div>
                                            {l.description && <div className="qp-line-desc">{l.description}</div>}
                                            <div className="qp-line-meta">
                                                Qtd. {lineQuantity(l)} · {linePrice(l)}
                                                {lineDiscount(l) && <> · <span className="qp-disc">{lineDiscount(l)}</span></>}
                                            </div>
                                        </div>
                                        <div className="qp-line-total">
                                            {money(Number(l.line_total ?? 0))}{b.suffix}
                                            {out && <span className="qp-line-out">Não incluído</span>}
                                        </div>
                                    </div>
                                );
                            })}
                            {t.count > 0 && (
                                <>
                                    {t.discount > 0 && (
                                        <>
                                            <div className="qp-sum is-sub"><span>Subtotal</span><span>{money(t.subtotal)}{b.suffix}</span></div>
                                            <div className="qp-sum is-package"><span>{discountLabel(selection.discount)}</span><span>{"−"}{money(t.discount)}{b.suffix}</span></div>
                                        </>
                                    )}
                                    <div className="qp-sum is-total"><span>{b.totalLabel}</span><span>{money(t.total)}{b.suffix}</span></div>
                                </>
                            )}
                            {t.count === 0 && <div className="qp-sum is-sub"><span>Nenhum serviço incluído</span><span /></div>}
                        </section>
                    );
                })}

                {selection.discount.reason && <div className="qp-dropped" role="status">{selection.discount.reason}</div>}

                <div className="qp-totals">
                    {BUCKETS.filter((b) => linesOf(b.key).length > 0).map((b) => (
                        <div className="qp-total-box" key={b.key}>
                            <div className="qp-label">{b.totalLabel}</div>
                            <div className="qp-value">{money(selection.buckets[b.key].total)} <span className="qp-unit">{b.suffix}</span></div>
                        </div>
                    ))}
                </div>
                <div className="qp-vat">{data.document.vat_note}</div>

                <div className="qp-conditions">
                    <div className="qp-label">Condições</div>
                    {data.document.conditions.map((c) => (
                        <div className="qp-cond" key={c.key}><div className="qp-cond-key">{c.key}</div><div>{c.value}</div></div>
                    ))}
                </div>

                <div className="qp-pdf">{pdfButton}</div>
                <QuoteContact data={data} intro="Dúvidas sobre esta proposta? Fale connosco:" />
            </div>
            <QuoteFooter data={data} />

            {respondable && (
                <div className="qp-actions">
                    <div className="qp-actions-inner">
                        {data.has_optional && (
                            <div className="qp-actions-summary">
                                <span>{chosenLines.length} de {data.lines.length} serviços</span>
                                <span>
                                    {selection.buckets.monthly.count > 0 && <strong>{money(selection.buckets.monthly.total)}/mês</strong>}
                                    {selection.buckets.monthly.count > 0 && selection.buckets.one_off.count > 0 && " + "}
                                    {selection.buckets.one_off.count > 0 && <strong>{money(selection.buckets.one_off.total)}</strong>}
                                </span>
                            </div>
                        )}
                        <button type="button" className="qp-btn is-primary is-block" onClick={() => open("accept")} disabled={chosenLines.length === 0}>
                            Aceitar o orçamento
                        </button>
                        <div className="qp-actions-secondary">
                            <button type="button" className="qp-link-btn" onClick={() => open("changes")}>Pedir alterações</button>
                            <button type="button" className="qp-link-btn" onClick={() => open("refuse")}>Recusar</button>
                        </div>
                    </div>
                </div>
            )}
            {!respondable && data.preview && (
                <div className="qp-actions">
                    <div className="qp-actions-inner">
                        <button type="button" className="qp-btn is-primary is-block" disabled>Aceitar o orçamento</button>
                        <div className="qp-actions-secondary">
                            <button type="button" className="qp-link-btn" disabled>Pedir alterações</button>
                            <button type="button" className="qp-link-btn" disabled>Recusar</button>
                        </div>
                    </div>
                </div>
            )}

            {sheet && (
                <div className="qp-sheet-backdrop" role="dialog" aria-modal="true" onClick={(e) => { if (e.target === e.currentTarget && !busy) setSheet(null); }}>
                    <div className="qp-sheet">
                        {sheet === "accept" && (
                            <>
                                <h2>Confirmar a aceitação</h2>
                                <p>Confirme os serviços e os seus dados. Depois de aceite, a resposta não pode ser alterada.</p>
                                <div className="qp-sheet-summary">
                                    <strong>Serviços incluídos</strong>
                                    <ul>{chosenLines.map((l) => <li key={l.key}>{l.name}</li>)}</ul>
                                    {selection.buckets.monthly.count > 0 && <div>Total mensal: <strong>{money(selection.buckets.monthly.total)}/mês</strong></div>}
                                    {selection.buckets.one_off.count > 0 && <div>Total valor único: <strong>{money(selection.buckets.one_off.total)}</strong></div>}
                                    {selection.discount.reason && <div className="qp-dropped">{selection.discount.reason}</div>}
                                    <div className="qp-muted" style={{ fontSize: 12.5, marginTop: 6 }}>{data.document.vat_note}</div>
                                </div>
                                <label className="qp-field">
                                    <span>Nome</span>
                                    <input autoComplete="name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                                    {errors.name && <div className="qp-error">{errors.name}</div>}
                                </label>
                                <label className="qp-field">
                                    <span>Email</span>
                                    <input type="email" autoComplete="email" inputMode="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
                                    {errors.email && <div className="qp-error">{errors.email}</div>}
                                </label>
                                <label className="qp-terms">
                                    <input type="checkbox" checked={form.terms} onChange={(e) => setForm({ ...form, terms: e.target.checked })} />
                                    <span>Li e aceito as condições deste orçamento.</span>
                                </label>
                                {errors.terms_accepted && <div className="qp-error" style={{ marginTop: -8, marginBottom: 10 }}>{errors.terms_accepted}</div>}
                                {(errors.lines || sheetError) && <div className="qp-error" style={{ marginBottom: 10 }}>{errors.lines || sheetError}</div>}
                                <div className="qp-sheet-buttons">
                                    <button type="button" className="qp-btn" onClick={() => setSheet(null)} disabled={busy}>Voltar</button>
                                    <button type="button" className="qp-btn is-primary" disabled={busy || !form.terms || !form.name.trim() || !form.email.trim()}
                                        onClick={() => onAccept && run(() => onAccept({ name: form.name.trim(), email: form.email.trim(), terms_accepted: form.terms, optional_keys: optionalKeys.filter((k) => included.has(k)) }))}>
                                        {busy ? "A registar…" : "Confirmar a aceitação"}
                                    </button>
                                </div>
                            </>
                        )}
                        {sheet === "changes" && (
                            <>
                                <h2>Pedir alterações</h2>
                                <p>Diga-nos o que gostaria de mudar. A equipa prepara uma nova versão e envia-lhe o link.</p>
                                <label className="qp-field">
                                    <span>O que gostaria de alterar</span>
                                    <textarea value={form.message} maxLength={3000} onChange={(e) => setForm({ ...form, message: e.target.value })} />
                                    {(errors.message || sheetError) && <div className="qp-error">{errors.message || sheetError}</div>}
                                </label>
                                <div className="qp-sheet-buttons">
                                    <button type="button" className="qp-btn" onClick={() => setSheet(null)} disabled={busy}>Voltar</button>
                                    <button type="button" className="qp-btn is-primary" disabled={busy || form.message.trim().length < 3}
                                        onClick={() => onRequestChanges && run(() => onRequestChanges(form.message.trim()), () => { setChangesSent(true); window.scrollTo({ top: 0, behavior: "smooth" }); })}>
                                        {busy ? "A enviar…" : "Enviar o pedido"}
                                    </button>
                                </div>
                            </>
                        )}
                        {sheet === "refuse" && (
                            <>
                                <h2>Recusar o orçamento</h2>
                                <p>Se quiser, diga-nos o motivo. Ajuda-nos a melhorar as próximas propostas.</p>
                                <label className="qp-field">
                                    <span>Motivo (opcional)</span>
                                    <textarea value={form.reason} maxLength={2000} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
                                    {(errors.reason || sheetError) && <div className="qp-error">{errors.reason || sheetError}</div>}
                                </label>
                                <div className="qp-sheet-buttons">
                                    <button type="button" className="qp-btn" onClick={() => setSheet(null)} disabled={busy}>Voltar</button>
                                    <button type="button" className="qp-btn is-danger" disabled={busy}
                                        onClick={() => onRefuse && run(() => onRefuse(form.reason.trim()), () => window.scrollTo({ top: 0, behavior: "smooth" }))}>
                                        {busy ? "A registar…" : "Confirmar a recusa"}
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
