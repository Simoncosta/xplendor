import { useEffect, useRef, useState } from "react";
import { Button, Input, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { searchRecipeComponents } from "helpers/laravel_helper";
import { RecipeComponentHit } from "common/models/recipe.model";
import { fmtDate } from "./recipeFormat";

/**
 * Fatia 4 — escolher um componente para a ficha técnica: uma caixa que procura por código,
 * descrição e fornecedor (a mesma pesquisa do ArticlePicker das faturas). Cada resultado mostra a
 * família, a unidade, a última compra e se é inativo ou semi-produto. ↑ ↓ escolhem, Enter junta.
 */
export default function ComponentPicker({ isOpen, companyId, exclude, onPick, onClose }: {
    isOpen: boolean; companyId: number; exclude: string[]; onPick: (c: RecipeComponentHit) => void; onClose: () => void;
}) {
    const [q, setQ] = useState("");
    const [results, setResults] = useState<RecipeComponentHit[]>([]);
    const [loading, setLoading] = useState(false);
    const [active, setActive] = useState(0);
    const inputRef = useRef<HTMLInputElement | null>(null);
    const seq = useRef(0);

    useEffect(() => { if (isOpen) { setQ(""); setResults([]); setActive(0); } }, [isOpen]);

    useEffect(() => {
        if (!isOpen || !companyId || q.trim().length < 2) { setResults([]); return; }
        const mine = ++seq.current;
        setLoading(true);
        const t = setTimeout(async () => {
            try {
                const res: any = await searchRecipeComponents(companyId, q.trim());
                if (mine === seq.current) { setResults((res?.data?.components ?? []).filter((c: RecipeComponentHit) => !exclude.includes(c.component_id))); setActive(0); }
            } catch {
                if (mine === seq.current) setResults([]);
            } finally {
                if (mine === seq.current) setLoading(false);
            }
        }, 250);
        return () => clearTimeout(t);
    }, [q, isOpen, companyId, exclude]);

    const onKey = (e: React.KeyboardEvent) => {
        if (e.key === "ArrowDown") { e.preventDefault(); setActive((i) => Math.min(i + 1, results.length - 1)); }
        else if (e.key === "ArrowUp") { e.preventDefault(); setActive((i) => Math.max(i - 1, 0)); }
        else if (e.key === "Enter") { e.preventDefault(); if (results[active]) onPick(results[active]); }
        else if (e.key === "Escape") { e.preventDefault(); onClose(); }
    };

    return (
        <Modal isOpen={isOpen} toggle={onClose} size="lg" fullscreen="sm" scrollable onOpened={() => inputRef.current?.focus()} data-testid="component-picker">
            <ModalHeader toggle={onClose}>Juntar componente</ModalHeader>
            <ModalBody>
                <Input innerRef={inputRef} value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={onKey}
                    placeholder="Código, descrição ou fornecedor…" aria-label="Pesquisar componente" role="combobox"
                    aria-expanded={results.length > 0} aria-controls="component-results"
                    aria-activedescendant={results[active] ? `component-option-${active}` : undefined} />
                <div className="mt-3" id="component-results" role="listbox" aria-label="Componentes">
                    {loading && <div className="text-muted fs-13"><Spinner size="sm" className="me-1" />A procurar…</div>}
                    {!loading && q.trim().length >= 2 && results.length === 0 && <div className="text-muted fs-13">Nenhum artigo para «{q.trim()}».</div>}
                    {!loading && q.trim().length < 2 && <div className="text-muted fs-13">Escreva pelo menos 2 letras.</div>}
                    {results.map((c, i) => (
                        <div key={c.component_id} id={`component-option-${i}`} role="option" aria-selected={i === active}
                            className={`border rounded p-2 mb-2 ${i === active ? "border-primary bg-primary-subtle" : ""}`} style={{ cursor: "pointer" }}
                            onMouseEnter={() => setActive(i)} onClick={() => onPick(c)}>
                            <div className="d-flex flex-wrap align-items-center gap-2">
                                <span className="fw-semibold">{c.description}</span>
                                {c.code && <span className="text-muted fs-12">{c.code}</span>}
                                {c.is_semi && <span className="badge bg-primary-subtle text-primary">semi-produto</span>}
                                {c.inactive && <span className="badge bg-secondary-subtle text-secondary">inativo</span>}
                                {c.approximate && <span className="badge bg-light text-body">aproximado</span>}
                            </div>
                            <div className="text-muted fs-12">
                                {[c.family, c.unit, c.last_purchase?.date ? `última compra ${fmtDate(c.last_purchase.date)}${c.last_purchase.supplier ? ` a ${c.last_purchase.supplier}` : ""}` : "sem compras"].filter(Boolean).join(" · ")}
                            </div>
                        </div>
                    ))}
                </div>
            </ModalBody>
            <ModalFooter>
                <Button type="button" color="light" onClick={onClose}>Cancelar</Button>
            </ModalFooter>
        </Modal>
    );
}
