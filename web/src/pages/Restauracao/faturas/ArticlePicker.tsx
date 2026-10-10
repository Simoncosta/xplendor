import { useEffect, useRef, useState } from "react";
import { Button, Input, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { searchOcrArticles } from "helpers/laravel_helper";
import { OcrArticleSearchResult } from "common/models/ocr.model";

/**
 * XPLENDOR — F2b: pesquisa de artigos para ligar uma linha da fatura. Uma só caixa que procura
 * por código, descrição, código do fornecedor e nome do fornecedor (sem acentos, por palavras).
 * Cada resultado mostra a unidade, a família e a última compra; os já comprados a este
 * fornecedor vêm primeiro, com selo. Teclado: ↑ ↓ para escolher, Enter para associar, Esc fecha.
 * No telemóvel abre em ecrã inteiro.
 */

type Props = {
    isOpen: boolean;
    companyId: number;
    supplierNif: string | null;
    supplierName: string | null;
    /** Texto inicial (a descrição da linha). */
    initialQuery: string;
    /** O que a fatura diz da linha (para comparar). */
    lineLabel: string;
    onPick: (a: OcrArticleSearchResult) => void;
    onCreate: () => void;
    onClose: () => void;
};

const eur = (n: number) => n.toLocaleString("pt-PT", { style: "currency", currency: "EUR", maximumFractionDigits: 4 });
const fmtDay = (d?: string | null) => (d ? d.split("-").reverse().join("/") : "");

export default function ArticlePicker({ isOpen, companyId, supplierNif, supplierName, initialQuery, lineLabel, onPick, onCreate, onClose }: Props) {
    const [q, setQ] = useState(initialQuery);
    const [results, setResults] = useState<OcrArticleSearchResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [active, setActive] = useState(0);
    const inputRef = useRef<HTMLInputElement | null>(null);
    const listRef = useRef<HTMLDivElement | null>(null);
    const seq = useRef(0);

    useEffect(() => { if (isOpen) { setQ(initialQuery); setActive(0); } }, [isOpen, initialQuery]);

    // Pesquisa com atraso curto (cada tecla não faz um pedido); só vale a última resposta.
    useEffect(() => {
        if (!isOpen || !companyId) return;
        const mine = ++seq.current;
        setLoading(true);
        const t = setTimeout(async () => {
            try {
                const res: any = await searchOcrArticles(companyId, { q, nif: supplierNif });
                if (mine === seq.current) { setResults(res?.data?.articles ?? []); setActive(0); }
            } catch {
                if (mine === seq.current) setResults([]);
            } finally {
                if (mine === seq.current) setLoading(false);
            }
        }, 250);
        return () => clearTimeout(t);
    }, [q, isOpen, companyId, supplierNif]);

    // Mantém o resultado ativo à vista.
    useEffect(() => {
        listRef.current?.querySelector<HTMLElement>(`[data-index="${active}"]`)?.scrollIntoView({ block: "nearest" });
    }, [active]);

    const onKey = (e: React.KeyboardEvent) => {
        if (e.key === "ArrowDown") { e.preventDefault(); setActive((i) => Math.min(i + 1, results.length - 1)); }
        else if (e.key === "ArrowUp") { e.preventDefault(); setActive((i) => Math.max(i - 1, 0)); }
        else if (e.key === "Enter") { e.preventDefault(); if (results[active]) onPick(results[active]); }
        else if (e.key === "Escape") { e.preventDefault(); onClose(); }
    };

    const optionId = (i: number) => `article-option-${i}`;

    return (
        <Modal isOpen={isOpen} toggle={onClose} size="lg" fullscreen="sm" scrollable onOpened={() => inputRef.current?.focus()} data-testid="article-picker">
            <ModalHeader toggle={onClose}>Associar a artigo</ModalHeader>
            <ModalBody>
                <p className="fs-13 text-muted mb-2">
                    Na fatura: <strong className="text-body">{lineLabel}</strong>
                    {supplierName && <> · fornecedor <strong className="text-body">{supplierName}</strong></>}
                </p>
                <div className="position-relative mb-2">
                    <Input
                        innerRef={inputRef}
                        bsSize="sm"
                        type="search"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        onKeyDown={onKey}
                        placeholder="Código, descrição, código do fornecedor ou fornecedor…"
                        aria-label="Pesquisar artigos"
                        role="combobox"
                        aria-expanded={results.length > 0}
                        aria-controls="article-results"
                        aria-activedescendant={results[active] ? optionId(active) : undefined}
                        data-testid="article-search"
                    />
                    {loading && <Spinner size="sm" className="position-absolute" style={{ right: 10, top: 7 }} aria-label="A pesquisar" />}
                </div>

                {!loading && results.length === 0 ? (
                    <div className="text-center text-muted py-4" data-testid="article-empty">
                        <p className="mb-2">{q.trim() ? <>Nenhum artigo encontrado para «{q.trim()}».</> : "Escreva para procurar."}</p>
                        <Button size="sm" color="outline-primary" onClick={onCreate}><i className="ri-add-line me-1" />Criar artigo</Button>
                    </div>
                ) : (
                    <>
                    {results[0]?.approximate && (
                        <p className="fs-12 text-muted mb-1" data-testid="article-approximate">Nenhum artigo tem todas as palavras: estes são os mais parecidos.</p>
                    )}
                    <div id="article-results" role="listbox" aria-label="Artigos" ref={listRef} className="list-group" data-testid="article-results">
                        {results.map((a, i) => (
                            <button
                                key={a.id}
                                id={optionId(i)}
                                type="button"
                                role="option"
                                aria-selected={i === active}
                                data-index={i}
                                className={`list-group-item list-group-item-action text-start ${i === active ? "active" : ""}`}
                                onMouseEnter={() => setActive(i)}
                                onClick={() => onPick(a)}
                            >
                                <div className="d-flex flex-wrap justify-content-between gap-2">
                                    <span className="fw-medium">
                                        <span className="font-monospace me-2">{a.code ?? "—"}</span>{a.description ?? "—"}
                                    </span>
                                    {a.bought_from_supplier && <span className={`badge ${i === active ? "bg-light text-success" : "bg-success-subtle text-success"}`}>Já comprado a este fornecedor</span>}
                                </div>
                                <div className={`fs-12 ${i === active ? "" : "text-muted"}`}>
                                    {[a.unit, a.family].filter(Boolean).join(" · ")}
                                    {a.supplier_codes.length > 0 && <> · cód. fornecedor {a.supplier_codes.join(", ")}</>}
                                    {a.last_purchase && <> · última compra {eur(a.last_purchase.price)}{a.last_purchase.unit ? `/${a.last_purchase.unit}` : ""} em {fmtDay(a.last_purchase.date)}{a.last_purchase.supplier ? ` (${a.last_purchase.supplier})` : ""}</>}
                                </div>
                            </button>
                        ))}
                    </div>
                    </>
                )}
            </ModalBody>
            <ModalFooter className="justify-content-between">
                <span className="fs-12 text-muted d-none d-md-inline">↑ ↓ escolher · Enter associar · Esc fechar</span>
                <div className="d-flex gap-2">
                    <Button color="light" onClick={onClose}>Cancelar</Button>
                    <Button color="outline-primary" onClick={onCreate}><i className="ri-add-line me-1" />Criar artigo</Button>
                </div>
            </ModalFooter>
        </Modal>
    );
}
