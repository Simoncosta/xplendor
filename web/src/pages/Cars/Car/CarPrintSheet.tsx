import { useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { Spinner } from "reactstrap";
import { getCarPrintSheet } from "helpers/laravel_helper";
import type { CarPrintSheet as CarPrintSheetType, CarPresentation } from "types/api";
import "./CarPrintSheet.fonts.css";

/**
 * Ficha de impressão A4 da viatura (a folha que o stand imprime e põe na viatura).
 *
 * Desenha o campo `presentation` montado na plataforma (CarPresentation, o mesmo da API pública
 * e, depois, do site do cliente), com a hierarquia da ficha do site adaptada a papel:
 * cabeçalho (logótipo, tipologia · estado · ref., título, ano · origem · cor, preço), números-chave,
 * blocos (detalhes, equipamento com ✓, camas com explicação), a descrição e o rodapé (QR Code,
 * contacto do vendedor ou o contacto comercial da empresa, a nota da empresa, o aviso legal, identificação da
 * empresa). Sem galeria nem botões na folha.
 *
 * Técnica: HTML + @media print + @page A4 + window.print() do browser. Feita para se ler bem a
 * preto e branco: texto escuro, linhas finas, sem fundos de cor. Os blocos não se partem entre
 * colunas nem entre páginas; com muito equipamento ou uma descrição longa, a ficha reduz-se por
 * igual (sem cortar nada) para caber numa folha.
 *
 * A redução é uma escala do tipo de letra e dos espaços (tudo em em, a partir de --ps-fit), nunca
 * um zoom: a folha tem no máximo 210 mm com qualquer tipo de letra e em qualquer browser (o zoom
 * com a largura compensada passava a folha no Chrome atual e cortava a impressão). O tipo de letra
 * é o Inter servido pela app, igual em Mac e Windows; o fator calcula-se depois de ele carregar e
 * outra vez antes de imprimir. Se nem com o fator mínimo couber, a ficha passa a 2 páginas limpas
 * (sem blocos partidos), em vez de cortar texto.
 *
 * Opções da ficha: um painel ao lado da folha (por cima, em ecrãs estreitos; nunca sai na
 * impressão) liga ou desliga a descrição da viatura (desligada por omissão: as visitas são feitas
 * com o comercial) e a nota da ficha (ligada, se a empresa a tiver). A folha muda na hora, o fator
 * volta a calcular-se e a escolha fica no browser, por empresa. A descrição não se apaga: é a mesma
 * do site. O aviso legal, a identificação da empresa, o contacto e o QR ficam sempre.
 */

const publicUrl = process.env.REACT_APP_PUBLIC_URL ?? "";

// Área útil da folha A4 com as margens do @page (297 - 11 - 10 mm), em px CSS (96 por polegada).
const A4_CONTENT_PX = (276 * 96) / 25.4;
// Abaixo disto a letra fica pequena demais para ler na viatura: passa antes para uma segunda folha.
const MIN_FIT = 0.72;

// A descrição vem do editor do painel em HTML (o site mostra-a assim): na folha, texto simples
// em parágrafos, sem os estilos colados. O DOMParser não corre scripts nem carrega imagens.
export function descriptionParagraphs(html: string): string[] {
    const marked = html
        .replace(/<br\s*\/?>/gi, "\n")
        .replace(/<\/(p|div|li|h[1-6]|blockquote)>/gi, "\n\n");
    const text = new DOMParser().parseFromString(marked, "text/html").body.textContent ?? "";
    return text.split(/\n\s*\n/).map((t) => t.replace(/[ \t\u00a0]+/g, " ").trim()).filter(Boolean);
}

// Código postal guardado sem hífen (4450745): na folha, 4450-745.
const postalCode = (value?: string | null) => (value && /^\d{7}$/.test(value) ? `${value.slice(0, 4)}-${value.slice(4)}` : value);

// Como o site: "57 900 €", com espaço também nos milhares de 4 algarismos ("9 500 €"; o pt-PT do
// Intl não os agrupa). Espaço fixo, para o preço não partir entre linhas.
// O contacto comercial da empresa em grupos de 3 ("917 349 847"), com o indicativo à parte
// ("+351 917 349 847"). Espaço fixo, para o número não partir entre linhas.
export function groupPhone(value: string): string {
    const t = value.replace(/\s+/g, "");
    const pt = /^(?:\+|00)351(\d+)$/.exec(t);
    const [prefix, digits] = pt ? ["+351 ", pt[1]] : t.startsWith("+") ? ["+", t.slice(1)] : ["", t];
    return prefix + digits.replace(/(\d{3})(?=\d)/g, "$1 ");
}

// O aviso legal da ficha: texto fixo, igual para todas as empresas.
export const LEGAL_NOTICE = "Este preçário serve apenas como uma prévia do veículo e não faz parte de um contrato de venda. As informações fornecidas aqui são descrições não vinculativas e não representam bens garantidos. O vendedor não se responsabiliza por erros, erros de introdução e erros de transmissão de dados.";

// As opções da ficha, por empresa, no browser. Sem armazenamento (ou com dados estranhos), as de omissão.
export type SheetOptions = { about: boolean; note: boolean };
export const DEFAULT_OPTIONS: SheetOptions = { about: false, note: true };
const optionsKey = (companyId: string | number) => `xp-ficha-opcoes:${companyId}`;
export function readOptions(companyId: string | number): SheetOptions {
    try {
        const saved = JSON.parse(window.localStorage.getItem(optionsKey(companyId)) ?? "null");
        if (!saved || typeof saved !== "object") return DEFAULT_OPTIONS;
        return {
            about: typeof saved.about === "boolean" ? saved.about : DEFAULT_OPTIONS.about,
            note: typeof saved.note === "boolean" ? saved.note : DEFAULT_OPTIONS.note,
        };
    } catch {
        return DEFAULT_OPTIONS;
    }
}
function writeOptions(companyId: string | number, options: SheetOptions): void {
    try { window.localStorage.setItem(optionsKey(companyId), JSON.stringify(options)); } catch { /* sem armazenamento: vale só nesta visita */ }
}

const euros = (value: number) => `${Math.round(value).toString().replace(/\B(?=(\d{3})+(?!\d))/g, "\u00a0")}\u00a0€`;

function PriceBox({ price }: { price: CarPresentation["price"] }) {
    if (price.hidden || price.current == null) {
        return <div className="ps-price"><span className="ps-price-current">{price.label ?? "Sob consulta"}</span></div>;
    }
    return (
        <div className="ps-price">
            {price.promo && price.regular != null && <span className="ps-price-old">{euros(price.regular)}</span>}
            <span className="ps-price-current">{euros(price.current)}</span>
            {price.promo && <span className="ps-price-tag">Promoção</span>}
        </div>
    );
}

function Section({ section }: { section: CarPresentation["sections"][number] }) {
    return (
        <section className="ps-section" data-testid={`ps-section-${section.id}`}>
            <h3 className="ps-section-title">{section.title}</h3>
            {section.details.length > 0 && (
                <dl className="ps-details">
                    {section.details.map((d) => (
                        <div key={d.label} className="ps-detail"><dt>{d.label}</dt><dd>{d.value}</dd></div>
                    ))}
                </dl>
            )}
            {section.beds && section.beds.length > 0 && (
                <ul className="ps-beds">
                    {section.beds.map((b, i) => (
                        <li key={`${b.label}-${i}`}><strong>{b.label}</strong>{b.description ? <span>{b.description}</span> : null}</li>
                    ))}
                </ul>
            )}
            {section.items.length > 0 && (
                <ul className="ps-items">
                    {section.items.map((it) => <li key={it}><span className="ps-check" aria-hidden>✓</span>{it}</li>)}
                </ul>
            )}
        </section>
    );
}

export default function CarPrintSheet() {
    const { companyId, id } = useParams();
    const navigate = useNavigate();
    const [data, setData] = useState<CarPrintSheetType | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    // Sem logótipo (ou se falhar a carregar), o nome da empresa em texto.
    const [logoBroken, setLogoBroken] = useState(false);
    // Fator para a ficha caber numa folha (1 = tamanho normal) e se, nem com o mínimo, precisa de 2 páginas.
    const [fit, setFit] = useState(1);
    const [twoPages, setTwoPages] = useState(false);
    const fitRef = useRef<HTMLDivElement>(null);
    // As opções da ficha (painel ao lado da folha), lembradas por empresa.
    const [options, setOptions] = useState<SheetOptions>(() => (companyId ? readOptions(companyId) : DEFAULT_OPTIONS));
    const changeOption = (key: keyof SheetOptions, value: boolean) => {
        const next = { ...options, [key]: value };
        setOptions(next);
        if (companyId) writeOptions(companyId, next);
    };

    // Procura o maior fator com que a ficha cabe na área útil do A4 (a largura no ecrã é a mesma
    // da folha, por isso o ecrã mostra o que sai na impressora). Mede-se a altura real em cada
    // tentativa: com a letra mais pequena cabe mais texto por linha e a altura não desce na proporção.
    const measure = useCallback(() => {
        const el = fitRef.current;
        if (!el) return;
        const available = A4_CONTENT_PX * 0.985;
        el.classList.add("ps-measuring");
        const heightAt = (f: number) => {
            el.style.setProperty("--ps-fit", String(f));
            return el.getBoundingClientRect().height;
        };
        let best = 1;
        let overflow = false;
        if (heightAt(1) > available) {
            if (heightAt(MIN_FIT) > available) { best = MIN_FIT; overflow = true; }
            else {
                let lo = MIN_FIT, hi = 1;
                for (let i = 0; i < 10; i++) {
                    const mid = (lo + hi) / 2;
                    if (heightAt(mid) <= available) lo = mid; else hi = mid;
                }
                best = Math.floor(lo * 1000) / 1000;
            }
        }
        el.style.setProperty("--ps-fit", String(best));
        el.classList.remove("ps-measuring");
        if (heightAt(best) > 0) { setFit(best); setTwoPages(overflow); }
    }, []);

    // O fator só se calcula com o Inter carregado (outra letra daria outras medidas) e volta a
    // calcular-se antes de imprimir e a cada mudança das opções da ficha.
    useLayoutEffect(() => {
        if (!data) return;
        let alive = true;
        const fonts = (document as Document & { fonts?: FontFaceSet }).fonts;
        (fonts?.ready ?? Promise.resolve()).then(() => { if (alive) measure(); });
        const beforePrint = () => measure();
        window.addEventListener("beforeprint", beforePrint);
        return () => { alive = false; window.removeEventListener("beforeprint", beforePrint); };
    }, [data, measure, options.about, options.note]);

    const goBack = () => {
        if (id) navigate(`/cars/${id}/ficha`);
        else window.history.back();
    };

    useEffect(() => {
        if (!companyId || !id) return;
        let alive = true;
        setLoading(true);
        getCarPrintSheet(Number(companyId), Number(id))
            .then((res: any) => { if (alive) setData((res?.data ?? res) as CarPrintSheetType); })
            .catch(() => { if (alive) setError(true); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId, id]);

    useEffect(() => {
        if (data) document.title = `Ficha: ${data.presentation.display_name}`;
    }, [data]);

    if (loading) {
        return <div className="d-flex justify-content-center align-items-center" style={{ minHeight: "60vh" }}><Spinner color="primary" /></div>;
    }
    if (error || !data) {
        return <div className="text-center text-muted py-5">Ficha indisponível.</div>;
    }

    const p = data.presentation;
    const c = data.company;
    const companyName = c.trade_name || c.fiscal_name || "";
    const logoUrl = c.logo_path ? (c.logo_path.startsWith("http") ? c.logo_path : publicUrl + c.logo_path) : null;
    const address = [c.address, postalCode(c.postal_code)].filter(Boolean).join(", ");
    const paragraphs = p.description ? descriptionParagraphs(p.description) : [];
    const sellerPhone = data.seller?.mobile || c.mobile || c.phone;
    // Com o contacto comercial da empresa, a ficha mostra-o e não o nome do vendedor.
    const printContact = data.print_contact?.phone ? data.print_contact : null;
    const note = data.print_note?.trim() ?? "";
    const showAbout = options.about && paragraphs.length > 0;
    const showNote = options.note && note !== "";

    return (
        <>
            <style>{PRINT_STYLES}</style>
            <div className="print-overlay">
                <div className="print-toolbar no-print">
                    <div className="print-toolbar-inner">
                        <button type="button" className="btn btn-outline-primary" onClick={goBack}><i className="ri-arrow-left-line me-1" /> Voltar</button>
                        <div className="print-toolbar-hint">Pré-visualização da ficha em A4. Clique em <strong>Imprimir</strong> para escolher a impressora ou guardar como PDF.</div>
                        <button type="button" className="btn btn-primary" onClick={() => window.print()}><i className="ri-printer-line me-1" /> Imprimir</button>
                    </div>
                    {!data.qr.available && (
                        <div className="print-toolbar-inner pt-0">
                            <div className="alert alert-warning mb-0 w-100 py-2" role="status" data-testid="ps-qr-missing">
                                <i className="ri-qr-code-line me-1" aria-hidden />
                                A ficha sai sem QR Code: a empresa não tem o <strong>Endereço da viatura no site</strong>. Preencha-o em{" "}
                                <Link to={c.id ? `/companies/${c.id}` : "/companies"}>Empresa › Dados gerais</Link>.
                            </div>
                        </div>
                    )}
                </div>

                <div className="print-body">
                <aside className="print-options no-print" data-testid="ps-options" aria-label="Opções da ficha">
                    <h2 className="print-options-title">Opções da ficha</h2>
                    {paragraphs.length > 0 && (
                        <div className="form-check form-switch">
                            <input id="ps-opt-about" type="checkbox" role="switch" className="form-check-input" checked={options.about}
                                onChange={(e) => changeOption("about", e.target.checked)} data-testid="ps-opt-about" />
                            <label htmlFor="ps-opt-about" className="form-check-label">Descrição da viatura</label>
                            <div className="print-options-help">O bloco "{p.description_title}". A descrição continua no site.</div>
                        </div>
                    )}
                    {note !== "" && (
                        <div className="form-check form-switch">
                            <input id="ps-opt-note" type="checkbox" role="switch" className="form-check-input" checked={options.note}
                                onChange={(e) => changeOption("note", e.target.checked)} data-testid="ps-opt-note" />
                            <label htmlFor="ps-opt-note" className="form-check-label">Nota da ficha</label>
                            <div className="print-options-help">A nota da empresa, no rodapé.</div>
                        </div>
                    )}
                    {paragraphs.length === 0 && note === "" && <div className="print-options-help">Esta ficha não tem blocos opcionais.</div>}
                    <div className={`print-options-fit ${twoPages ? "is-two" : "is-one"}`} data-testid="ps-fit-status" role="status">
                        <i className={twoPages ? "ri-file-copy-2-line me-1" : "ri-file-line me-1"} aria-hidden />
                        {twoPages ? "Passa a 2 páginas" : "Cabe numa página"}
                        {!twoPages && fit < 1 && <span className="print-options-help d-block">Letra reduzida a {Math.round(fit * 100)} %.</span>}
                    </div>
                    <div className="print-options-help">O aviso legal, a identificação da empresa, o contacto e o QR saem sempre. A escolha fica guardada neste browser.</div>
                </aside>
                <div className="print-sheet" data-testid="print-sheet">
                  <div className={`ps-fit${twoPages ? " ps-two-pages" : ""}`} ref={fitRef} style={{ ["--ps-fit" as string]: String(fit) }} data-fit={fit} data-pages={twoPages ? 2 : 1}>
                    <header className="ps-header">
                        <div className="ps-brand">
                            {logoUrl && !logoBroken
                                ? <img src={logoUrl} alt={companyName} className="ps-logo" onLoad={measure} onError={() => setLogoBroken(true)} />
                                : <div className="ps-logo-fallback">{companyName || "Stand"}</div>}
                        </div>
                        <div className="ps-head">
                            {p.eyebrow.length > 0 && <div className="ps-eyebrow">{p.eyebrow.join(" · ")}</div>}
                            <h1 className="ps-title">{p.display_name}</h1>
                            {p.meta.length > 0 && <div className="ps-meta">{p.meta.join(" · ")}</div>}
                        </div>
                        <PriceBox price={p.price} />
                    </header>

                    {p.key_figures.length > 0 && (
                        <div className="ps-figures" data-testid="ps-figures">
                            {p.key_figures.map((k) => (
                                <div key={k.key} className="ps-figure"><div className="ps-figure-value">{k.value}</div><div className="ps-figure-caption">{k.caption}</div></div>
                            ))}
                        </div>
                    )}

                    <div className="ps-sections">
                        {p.sections.map((s) => <Section key={s.id} section={s} />)}
                    </div>

                    {showAbout && (
                        <section className="ps-about" data-testid="ps-about">
                            <h3 className="ps-section-title">{p.description_title}</h3>
                            {paragraphs.map((t, i) => <p key={i}>{t}</p>)}
                        </section>
                    )}

                    <footer className="ps-footer">
                        {data.qr.available && (
                            <div className="ps-qr" data-testid="ps-qr">
                                <div className="ps-qr-code" dangerouslySetInnerHTML={{ __html: data.qr.svg }} />
                                <div className="ps-qr-label">{data.qr.site_label}</div>
                            </div>
                        )}
                        <div className="ps-contact">
                            {data.qr.available && <div className="ps-qr-hint">Veja esta viatura no site: aponte a câmara ao código.</div>}
                            {printContact
                                ? <div className="ps-seller" data-testid="ps-print-contact"><strong>{printContact.label || "Dep. Comercial"}: {groupPhone(printContact.phone)}</strong></div>
                                : <>
                                    {data.seller?.name && <div className="ps-seller"><strong>{data.seller.name}</strong>{sellerPhone ? ` · ${sellerPhone}` : ""}</div>}
                                    {!data.seller?.name && sellerPhone && <div className="ps-seller"><strong>{sellerPhone}</strong></div>}
                                </>}
                            {showNote && <p className="ps-note" data-testid="ps-note">{note}</p>}
                            <p className="ps-legal" data-testid="ps-legal">{LEGAL_NOTICE}</p>
                            <div className="ps-company">
                                <strong>{c.fiscal_name || companyName}</strong>
                                {c.nipc ? ` · NIPC ${c.nipc}` : ""}
                            </div>
                            <div className="ps-company-lines">
                                {[address, c.phone, c.email, c.website].filter(Boolean).join(" · ")}
                            </div>
                        </div>
                    </footer>
                  </div>
                </div>
                </div>
            </div>
        </>
    );
}

// ─────────────────────────────── Estilo A4 ───────────────────────────────────
// Inline num <style> para o CSS do painel não vazar para a impressão. Pensado para o preto e
// branco: tudo em tons de cinzento escuro, sem fundos coloridos (lê-se igual numa impressora
// a cores ou a preto).
const PRINT_STYLES = `
@page { size: A4 portrait; margin: 11mm 12mm 10mm 12mm; }

.print-overlay {
    position: fixed; inset: 0; z-index: 1500; background: #f3f4f6; overflow-y: auto;
    font-family: 'XplInterSheet', Arial, sans-serif;
    color: #111827;
}
.print-toolbar { position: sticky; top: 0; z-index: 10; background: #fff; border-bottom: 1px solid #e5e7eb; box-shadow: 0 2px 6px rgba(15,23,42,.06); }
.print-toolbar-inner { max-width: 1000px; margin: 0 auto; padding: 12px 20px; display: flex; align-items: center; gap: 12px; }
.print-toolbar-hint { flex: 1; font-size: 12px; color: #6b7280; text-align: center; }
@media (max-width: 640px) { .print-toolbar-hint { display: none; } }

/* A folha nunca passa a largura do A4: tudo o que está dentro encolhe ou quebra linha. */
.print-sheet { box-sizing: border-box; max-width: 210mm; line-height: 1.3; overflow-wrap: anywhere; }
.print-sheet *, .print-sheet *::before, .print-sheet *::after { box-sizing: border-box; min-width: 0; }
.print-sheet h1, .print-sheet h3 { font-family: inherit; }
/* A redução para caber numa folha: o tamanho da letra (e, com ele, todos os espaços em em) escala com
   --ps-fit. A largura não muda; a altura mínima mantém o rodapé no fundo. */
.ps-fit { display: flex; flex-direction: column; width: 100%; font-size: calc(10.5pt * var(--ps-fit, 1)); min-height: 276mm; }
.ps-fit.ps-measuring, .ps-fit.ps-two-pages { min-height: 0; }
@media screen {
    .print-sheet { width: 210mm; min-height: 297mm; margin: 20px auto; padding: 11mm 12mm 10mm 12mm; background: #fff; box-shadow: 0 20px 40px rgba(15,23,42,.10); }
    /* A folha e, à direita, o painel das opções (fixo ao fazer scroll); em ecrãs estreitos, o painel passa para cima. */
    .print-body { display: flex; justify-content: center; align-items: flex-start; gap: 24px; padding: 0 20px; }
    .print-body .print-sheet { margin: 20px 0; flex: 0 0 auto; }
    .print-options { order: 2; flex: 0 0 260px; position: sticky; top: 84px; margin-top: 20px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
        padding: 16px; font-family: var(--vz-body-font-family, inherit); font-size: 13px; box-shadow: 0 2px 6px rgba(15,23,42,.06); }
    .print-options-title { font-size: 14px; font-weight: 600; margin: 0 0 12px; }
    .print-options .form-check { margin-bottom: 12px; }
    .print-options-help { font-size: 12px; color: #6b7280; }
    .print-options-fit { margin: 4px 0 12px; padding: 8px 10px; border-radius: 6px; font-weight: 600; }
    .print-options-fit.is-one { background: #ecfdf5; color: #047857; }
    .print-options-fit.is-two { background: #fffbeb; color: #b45309; }
    @media (max-width: 1100px) {
        .print-body { flex-direction: column; align-items: center; }
        .print-options { order: 0; position: static; width: 210mm; max-width: 100%; flex: 0 0 auto; }
    }
}
@media print {
    html, body { background: #fff !important; }
    .no-print { display: none !important; }
    .print-overlay { position: static !important; overflow: visible !important; background: #fff !important; inset: auto !important; }
    .print-sheet { width: 100%; max-width: 100%; margin: 0; padding: 0; box-shadow: none; }
    .print-body { display: block; padding: 0; }
    body > *:not(#root) { display: none !important; }
}

/* Cabeçalho: o título encolhe e quebra linha; o preço nunca o empurra para fora. */
.ps-header { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 1.6em; align-items: start; padding-bottom: .9em; border-bottom: 1.5pt solid #111827; }
.ps-brand { max-width: 42mm; }
.ps-logo { max-height: 16mm; max-width: 100%; object-fit: contain; display: block; }
.ps-logo-fallback { font-weight: 800; font-size: 1.238em; letter-spacing: .02em; text-transform: uppercase; }
.ps-eyebrow { font-size: .81em; text-transform: uppercase; letter-spacing: .08em; color: #4b5563; margin-bottom: .27em; }
.ps-title { font-size: 2em; line-height: 1.1; font-weight: 800; margin: 0; color: #111827; overflow-wrap: break-word; }
.ps-meta { margin-top: .4em; color: #374151; }
.ps-price { text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: .14em; white-space: nowrap; }
.ps-price-current { font-size: 2em; font-weight: 800; line-height: 1.1; }
.ps-price-old { font-size: 1.048em; color: #4b5563; text-decoration: line-through; }
.ps-price-tag { font-size: .762em; text-transform: uppercase; letter-spacing: .08em; border: 1pt solid #111827; padding: .08em .4em; border-radius: .27em; }

/* Números-chave: as caixas repartem a largura e encolhem. */
.ps-figures { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr); gap: .81em; margin: .9em 0; }
.ps-figure { border: 1pt solid #9ca3af; border-radius: .54em; padding: .55em .5em; text-align: center; }
.ps-figure-value { font-size: 1.238em; font-weight: 800; }
.ps-figure-caption { font-size: .762em; text-transform: uppercase; letter-spacing: .06em; color: #4b5563; }

/* Blocos */
.ps-sections { column-count: 2; column-gap: 1.89em; flex: 1; }
.ps-section { break-inside: avoid; page-break-inside: avoid; margin: 0 0 .85em; }
.ps-section-title { font-size: .905em; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; margin: 0 0 .405em; padding-bottom: .27em; border-bottom: .75pt solid #6b7280; }
.ps-details { margin: 0 0 .405em; }
.ps-detail { display: flex; justify-content: space-between; gap: .81em; padding: .16em 0; border-bottom: .5pt dotted #d1d5db; }
.ps-detail dt { font-weight: 400; color: #374151; flex: 0 1 auto; }
.ps-detail dd { margin: 0; font-weight: 700; text-align: right; flex: 0 1 auto; }
.ps-items { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: .81em; }
.ps-items li { padding: .06em 0; display: flex; gap: .405em; align-items: baseline; }
.ps-check { font-weight: 800; flex: 0 0 auto; }
.ps-beds { list-style: none; margin: 0 0 .405em; padding: 0; }
.ps-beds li { padding: .16em 0; }
.ps-beds li span { display: block; font-size: .857em; color: #4b5563; }

/* Descrição */
.ps-about { break-inside: avoid; margin-top: .27em; }
.ps-about p { margin: 0 0 .405em; }

/* Rodapé */
.ps-footer { display: flex; gap: 1.6em; align-items: center; margin-top: .9em; padding-top: .9em; border-top: 1.5pt solid #111827; break-inside: avoid; page-break-inside: avoid; }
.ps-qr { flex: 0 0 auto; text-align: center; max-width: 40mm; }
/* 32 mm na folha, seja qual for o fator (o QR não escala com a letra). */
.ps-qr-code { width: 32mm; height: 32mm; margin: 0 auto; background: #fff; }
.ps-qr-code svg { width: 100%; height: 100%; display: block; }
.ps-qr-label { font-size: 8pt; margin-top: 1mm; font-weight: 700; word-break: break-all; }
.ps-contact { flex: 1 1 auto; font-size: .905em; }
.ps-qr-hint { font-size: .947em; color: #374151; margin-bottom: .405em; }
.ps-seller { font-size: 1.158em; margin-bottom: .27em; }
/* Nota da empresa (até 250 caracteres): as quebras de linha escritas no painel mantêm-se. */
.ps-note { margin: 0 0 .35em; white-space: pre-line; }
/* Aviso legal: letra pequena, mas nunca abaixo de 6,5 pt (lê-se na folha impressa). */
.ps-legal { font-size: max(6.5pt, .82em); line-height: 1.3; color: #4b5563; margin: 0 0 .35em; }
.ps-company-lines { color: #374151; }
`;
