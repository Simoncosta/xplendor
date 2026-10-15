import { useEffect, useRef, useState } from "react";
import { Button, Input, Spinner } from "reactstrap";
import { searchRecipeComponents } from "helpers/laravel_helper";
import { RecipeComponentHit } from "common/models/recipe.model";
import { fmtDate } from "./recipeFormat";

/**
 * Fatia 4 — adicionar um componente à ficha técnica: uma caixa que procura por código, descrição e
 * fornecedor (a mesma pesquisa do ArticlePicker das faturas). Cada resultado mostra a família, a
 * unidade, a última compra e se é inativo ou semi-produto. ↑ ↓ escolhem, Enter adiciona, Esc fecha.
 * Abre dentro do modal do editor (por baixo das linhas), não num segundo modal.
 */
export default function ComponentPicker({ companyId, exclude, onPick, onClose }: {
    companyId: number; exclude: string[]; onPick: (c: RecipeComponentHit) => void; onClose: () => void;
}) {
    const [q, setQ] = useState("");
    const [results, setResults] = useState<RecipeComponentHit[]>([]);
    const [loading, setLoading] = useState(false);
    const [active, setActive] = useState(0);
    const inputRef = useRef<HTMLInputElement | null>(null);
    const rootRef = useRef<HTMLDivElement | null>(null);
    const seq = useRef(0);

    useEffect(() => { inputRef.current?.focus(); }, []);
    // Fica no fim das linhas: quando chegam resultados, desliza o corpo do modal até eles se verem.
    useEffect(() => { rootRef.current?.scrollIntoView?.({ block: "nearest" }); }, [results.length, loading]);

    useEffect(() => {
        if (!companyId || q.trim().length < 2) { setResults([]); return; }
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
    }, [q, companyId, exclude]);

    const onKey = (e: React.KeyboardEvent) => {
        if (e.key === "ArrowDown") { e.preventDefault(); setActive((i) => Math.min(i + 1, results.length - 1)); }
        else if (e.key === "ArrowUp") { e.preventDefault(); setActive((i) => Math.max(i - 1, 0)); }
        else if (e.key === "Enter") { e.preventDefault(); if (results[active]) onPick(results[active]); }
        // O Esc fecha só a pesquisa, não o modal do editor.
        else if (e.key === "Escape") { e.preventDefault(); e.stopPropagation(); onClose(); }
    };

    return (
        <div ref={rootRef} className="border rounded p-3 bg-light-subtle" data-testid="component-picker">
            <div className="d-flex align-items-center gap-2 mb-2">
                <span className="fw-semibold fs-13">Adicionar componente</span>
                <Button type="button" size="sm" color="link" className="ms-auto p-0" onClick={onClose}>Cancelar</Button>
            </div>
            <Input innerRef={inputRef} value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={onKey}
                // O modal do reactstrap fecha no keyup do Esc: aqui o Esc é só da pesquisa.
                onKeyUp={(e) => { if (e.key === "Escape") e.stopPropagation(); }}
                placeholder="Código, descrição ou fornecedor…" aria-label="Pesquisar componente" role="combobox"
                aria-expanded={results.length > 0} aria-controls="component-results"
                aria-activedescendant={results[active] ? `component-option-${active}` : undefined} />
            <div className="mt-2" id="component-results" role="listbox" aria-label="Componentes" style={{ maxHeight: 300, overflowY: "auto" }}>
                {loading && <div className="text-muted fs-13"><Spinner size="sm" className="me-1" />A procurar…</div>}
                {!loading && q.trim().length >= 2 && results.length === 0 && <div className="text-muted fs-13">Nenhum artigo para «{q.trim()}».</div>}
                {!loading && q.trim().length < 2 && <div className="text-muted fs-13">Escreva pelo menos 2 letras.</div>}
                {results.map((c, i) => (
                    <div key={c.component_id} id={`component-option-${i}`} role="option" aria-selected={i === active}
                        className={`border rounded p-2 mb-2 ${i === active ? "border-primary bg-primary-subtle" : "bg-body"}`} style={{ cursor: "pointer" }}
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
        </div>
    );
}
