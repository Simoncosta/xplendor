import { useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { Spinner } from "reactstrap";
import { getCarPrintSheet } from "helpers/laravel_helper";
import type { CarPrintSheet as CarPrintSheetType, CarPresentation } from "types/api";

/**
 * Ficha de impressão A4 da viatura (a folha que o stand imprime e põe na viatura).
 *
 * Desenha o campo `presentation` montado na plataforma (CarPresentation, o mesmo da API pública
 * e, depois, do site do cliente), com a hierarquia da ficha do site adaptada a papel:
 * cabeçalho (logótipo, tipologia · estado · ref., título, ano · origem · cor, preço), números-chave,
 * blocos (detalhes, equipamento com ✓, camas com explicação), a descrição e o rodapé (QR Code,
 * contacto do vendedor, identificação da empresa). Sem galeria nem botões na folha.
 *
 * Técnica: HTML + @media print + @page A4 + window.print() do browser. Feita para se ler bem a
 * preto e branco: texto escuro, linhas finas, sem fundos de cor. Os blocos não se partem entre
 * colunas nem entre páginas; com muito equipamento ou uma descrição longa, a ficha reduz-se por
 * igual (sem cortar nada) para caber numa folha.
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
    // Fator para a ficha caber numa folha (1 = tamanho normal).
    const [fit, setFit] = useState(1);
    const fitRef = useRef<HTMLDivElement>(null);

    // Procura o maior fator com que a ficha cabe na área útil do A4 (a largura no ecrã é a mesma
    // da folha, por isso o ecrã mostra o que sai na impressora). Mede-se a altura real em cada
    // tentativa: reduzida, cabe mais texto por linha e a altura não desce na proporção.
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
        if (heightAt(1) > available) {
            if (heightAt(MIN_FIT) > available) best = MIN_FIT;
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
        if (heightAt(best) > 0) setFit(best);
    }, []);

    useLayoutEffect(() => { if (data) measure(); }, [data, measure]);

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

                <div className="print-sheet" data-testid="print-sheet">
                  <div className="ps-fit" ref={fitRef} style={{ ["--ps-fit" as string]: String(fit) }} data-fit={fit}>
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

                    {paragraphs.length > 0 && (
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
                            {data.seller?.name && <div className="ps-seller"><strong>{data.seller.name}</strong>{sellerPhone ? ` · ${sellerPhone}` : ""}</div>}
                            {!data.seller?.name && sellerPhone && <div className="ps-seller"><strong>{sellerPhone}</strong></div>}
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
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color: #111827;
}
.print-toolbar { position: sticky; top: 0; z-index: 10; background: #fff; border-bottom: 1px solid #e5e7eb; box-shadow: 0 2px 6px rgba(15,23,42,.06); }
.print-toolbar-inner { max-width: 1000px; margin: 0 auto; padding: 12px 20px; display: flex; align-items: center; gap: 12px; }
.print-toolbar-hint { flex: 1; font-size: 12px; color: #6b7280; text-align: center; }
@media (max-width: 640px) { .print-toolbar-hint { display: none; } }

.print-sheet { font-size: 10.5pt; line-height: 1.35; }
/* A redução para caber numa folha: o zoom encolhe por igual, a largura compensa-o para a ficha
   ocupar a largura da folha e a altura mínima mantém o rodapé no fundo. */
.ps-fit { display: flex; flex-direction: column; zoom: var(--ps-fit, 1); width: calc(100% / var(--ps-fit, 1)); min-height: calc(276mm / var(--ps-fit, 1)); }
.ps-fit.ps-measuring { min-height: 0; }
@media screen {
    .print-sheet { width: 210mm; min-height: 297mm; margin: 20px auto; padding: 11mm 12mm 10mm 12mm; background: #fff; box-shadow: 0 20px 40px rgba(15,23,42,.10); box-sizing: border-box; }
}
@media print {
    html, body { background: #fff !important; }
    .no-print { display: none !important; }
    .print-overlay { position: static !important; overflow: visible !important; background: #fff !important; inset: auto !important; }
    .print-sheet { width: 100%; margin: 0; padding: 0; box-shadow: none; }
    body > *:not(#root) { display: none !important; }
}

/* Cabeçalho */
.ps-header { display: grid; grid-template-columns: auto 1fr auto; gap: 6mm; align-items: start; padding-bottom: 4mm; border-bottom: 1.5pt solid #111827; }
.ps-logo { max-height: 16mm; max-width: 42mm; object-fit: contain; }
.ps-logo-fallback { font-weight: 800; font-size: 13pt; letter-spacing: .02em; text-transform: uppercase; max-width: 42mm; }
.ps-eyebrow { font-size: 8.5pt; text-transform: uppercase; letter-spacing: .08em; color: #4b5563; margin-bottom: 1mm; }
.ps-title { font-size: 21pt; line-height: 1.1; font-weight: 800; margin: 0; color: #111827; }
.ps-meta { margin-top: 1.5mm; font-size: 10.5pt; color: #374151; }
.ps-price { text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: .5mm; white-space: nowrap; }
.ps-price-current { font-size: 21pt; font-weight: 800; line-height: 1.1; }
.ps-price-old { font-size: 11pt; color: #4b5563; text-decoration: line-through; }
.ps-price-tag { font-size: 8pt; text-transform: uppercase; letter-spacing: .08em; border: 1pt solid #111827; padding: .3mm 1.5mm; border-radius: 1mm; }

/* Números-chave */
.ps-figures { display: grid; grid-template-columns: repeat(auto-fit, minmax(30mm, 1fr)); gap: 3mm; margin: 4mm 0; }
.ps-figure { border: 1pt solid #9ca3af; border-radius: 2mm; padding: 2.5mm 3mm; text-align: center; }
.ps-figure-value { font-size: 13pt; font-weight: 800; }
.ps-figure-caption { font-size: 8pt; text-transform: uppercase; letter-spacing: .06em; color: #4b5563; }

/* Blocos */
.ps-sections { column-count: 2; column-gap: 7mm; flex: 1; }
.ps-section { break-inside: avoid; page-break-inside: avoid; margin: 0 0 4mm; }
.ps-section-title { font-size: 9.5pt; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; margin: 0 0 1.5mm; padding-bottom: 1mm; border-bottom: .75pt solid #6b7280; }
.ps-details { margin: 0 0 1.5mm; }
.ps-detail { display: flex; justify-content: space-between; gap: 3mm; padding: .6mm 0; border-bottom: .5pt dotted #d1d5db; }
.ps-detail dt { font-weight: 400; color: #374151; }
.ps-detail dd { margin: 0; font-weight: 700; text-align: right; }
.ps-items { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: 1fr 1fr; column-gap: 3mm; }
.ps-items li { padding: .4mm 0; display: flex; gap: 1.5mm; align-items: baseline; }
.ps-check { font-weight: 800; }
.ps-beds { list-style: none; margin: 0 0 1.5mm; padding: 0; }
.ps-beds li { padding: .6mm 0; }
.ps-beds li span { display: block; font-size: 9pt; color: #4b5563; }

/* Descrição */
.ps-about { break-inside: avoid; margin-top: 1mm; }
.ps-about p { margin: 0 0 1.5mm; }

/* Rodapé */
.ps-footer { display: flex; gap: 6mm; align-items: center; margin-top: 4mm; padding-top: 4mm; border-top: 1.5pt solid #111827; break-inside: avoid; }
.ps-qr { flex: 0 0 auto; text-align: center; }
/* 32 mm na folha mesmo quando a ficha se reduz para caber (o zoom do .ps-fit é compensado). */
.ps-qr-code { width: calc(32mm / var(--ps-fit, 1)); height: calc(32mm / var(--ps-fit, 1)); background: #fff; }
.ps-qr-code svg { width: 100%; height: 100%; display: block; }
.ps-qr-label { font-size: 8pt; margin-top: 1mm; font-weight: 700; word-break: break-all; max-width: 40mm; }
.ps-contact { flex: 1; font-size: 9.5pt; }
.ps-qr-hint { font-size: 9pt; color: #374151; margin-bottom: 1.5mm; }
.ps-seller { font-size: 11pt; margin-bottom: 1mm; }
.ps-company-lines { color: #374151; }
`;
