import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Input, InputGroup, InputGroupText } from "reactstrap";
import {
    getFormSearchIndex,
    normalizeForSearch,
    type FormSearchEntry,
} from "../data/formSearchIndex";

interface FormSearchBarProps {
    /**
     * Chamado quando o utilizador escolhe um resultado (Enter ou clique).
     * Na etapa 5 ligamos isto ao `useFieldSpotlight` que abre o accordion
     * + faz scroll + destaca o header. Na etapa 4 fica dummy (toast/log).
     */
    onSelect: (entry: FormSearchEntry) => void;
}

const MAX_RESULTS = 10;
const MIN_QUERY_LEN = 2;

/**
 * Busca universal estilo Spotlight no topo do formulário de viatura.
 *
 * Funcionalidade:
 *   - Filtra ~277 entradas (135 campos JSX manuais + 142 items de extras
 *     auto-derivados) por includes normalizado (acentos strip + lowercase).
 *   - Ordem por relevância: match no início do label > match no meio;
 *     desempate alfabético. Maximum 10 resultados visíveis (scroll interno
 *     se houver mais — mas com 277 entradas e queries >=2 chars, raro).
 *   - Keyboard: ↑/↓ navegam, Enter selecciona, Esc fecha + limpa.
 *   - Click fora do componente → fecha o dropdown (preserva o input).
 *   - Empty state honesto: "Sem resultados para 'X'" sem "desculpe".
 *
 * Estética: gradiente subtil + sombra leve quando o dropdown está aberto,
 * espelha tokens da CarList. A ousadia visual já foi gasta na estrela do
 * Relatório A — esta busca é utilitária, discreta.
 */
const FormSearchBar = ({ onSelect }: FormSearchBarProps) => {
    const [query, setQuery] = useState("");
    const [open, setOpen] = useState(false);
    const [activeIndex, setActiveIndex] = useState(0);
    const containerRef = useRef<HTMLDivElement>(null);

    // Índice é memoizado pelo próprio getFormSearchIndex (módulo-level cache).
    const index = useMemo(() => getFormSearchIndex(), []);

    const normalizedQuery = useMemo(() => normalizeForSearch(query.trim()), [query]);
    const trimmedQuery = query.trim();

    // Filtro + ordenação. Recalcula a cada keystroke (não há debounce na v1 —
    // .filter + .sort em ~277 entradas é <1ms; complicar só por complicar.).
    const results = useMemo<FormSearchEntry[]>(() => {
        if (normalizedQuery.length < MIN_QUERY_LEN) return [];
        const matches: { entry: FormSearchEntry; startsAt: number }[] = [];
        for (const entry of index) {
            const pos = entry.normalizedLabel.indexOf(normalizedQuery);
            if (pos === -1) continue;
            matches.push({ entry, startsAt: pos });
        }
        // Match no início (pos === 0) primeiro, depois resto;
        // desempate alfabético no label original.
        matches.sort((a, b) => {
            const aStart = a.startsAt === 0 ? 0 : 1;
            const bStart = b.startsAt === 0 ? 0 : 1;
            if (aStart !== bStart) return aStart - bStart;
            return a.entry.label.localeCompare(b.entry.label, "pt");
        });
        return matches.slice(0, MAX_RESULTS).map((m) => m.entry);
    }, [index, normalizedQuery]);

    // Reset activeIndex quando os resultados mudam (Matilde escreveu mais).
    useEffect(() => {
        setActiveIndex(0);
    }, [results.length]);

    // Click fora fecha o dropdown (preserva texto no input).
    useEffect(() => {
        if (!open) return;
        const onClickOutside = (e: MouseEvent) => {
            if (!containerRef.current) return;
            if (!containerRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener("mousedown", onClickOutside);
        return () => document.removeEventListener("mousedown", onClickOutside);
    }, [open]);

    const selectEntry = useCallback((entry: FormSearchEntry) => {
        onSelect(entry);
        // Não limpa o input para a Matilde poder repetir / refinar — apenas
        // fecha o dropdown.
        setOpen(false);
    }, [onSelect]);

    const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === "ArrowDown") {
            e.preventDefault();
            setActiveIndex((i) => Math.min(i + 1, Math.max(results.length - 1, 0)));
            if (!open) setOpen(true);
        } else if (e.key === "ArrowUp") {
            e.preventDefault();
            setActiveIndex((i) => Math.max(i - 1, 0));
        } else if (e.key === "Enter") {
            if (open && results[activeIndex]) {
                e.preventDefault();
                selectEntry(results[activeIndex]);
            }
        } else if (e.key === "Escape") {
            setOpen(false);
            setQuery("");
        }
    };

    const showDropdown = open && trimmedQuery.length >= MIN_QUERY_LEN;
    const showEmptyState = showDropdown && results.length === 0;

    // Padrão espelhado do sticky action bar de baixo (CarEditor commit 35e5d82):
    // fundo SÓLIDO branco, margens negativas X para ocupar largura total do
    // CardBody (cobre o `--vz-card-spacer-x` do Velzon), borda inferior limpa
    // a separar do conteúdo abaixo, marginBottom 1.5rem para o conteúdo ter
    // respiro real e não colar à barra durante o scroll. Z-index 10 chega
    // (não há overlays acima — o dropdown ainda fica acima, no z-index 11).
    return (
        <div
            ref={containerRef}
            style={{
                position: "sticky",
                top: 0,
                zIndex: 10,
                background: "#fff",
                borderBottom: "1px solid #e9ebec",
                marginLeft: "calc(-1 * var(--vz-card-spacer-x, 1.5rem))",
                marginRight: "calc(-1 * var(--vz-card-spacer-x, 1.5rem))",
                marginTop: "calc(-1 * var(--vz-card-spacer-y, 1.5rem))",
                marginBottom: "1.5rem",
                padding: "12px 18px",
            }}
        >
            <InputGroup>
                <InputGroupText className="bg-light border-end-0">
                    <i className="ri-search-line text-muted" aria-hidden="true" />
                </InputGroupText>
                <Input
                    type="search"
                    placeholder="Buscar campo do formulário…"
                    value={query}
                    onChange={(e) => {
                        setQuery(e.target.value);
                        if (!open) setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={onKeyDown}
                    className="border-start-0"
                    aria-autocomplete="list"
                    aria-controls="form-search-results"
                />
            </InputGroup>

            {showDropdown && (
                <div
                    id="form-search-results"
                    role="listbox"
                    style={{
                        position: "absolute",
                        left: 0,
                        right: 0,
                        marginTop: 4,
                        background: "#fff",
                        border: "1px solid #e9ebec",
                        borderRadius: 10,
                        boxShadow: "0 12px 32px rgba(15, 23, 42, 0.10)",
                        maxHeight: 420,
                        overflowY: "auto",
                        zIndex: 1021,
                    }}
                >
                    {showEmptyState && (
                        <div className="px-3 py-3 text-muted">
                            Sem resultados para <strong>"{trimmedQuery}"</strong>.
                        </div>
                    )}
                    {results.map((entry, i) => {
                        const isActive = i === activeIndex;
                        const sectionLabel = entry.location.kind === "section"
                            ? entry.location.sectionLabel
                            : entry.location.accordionLabel;
                        const kindIcon = entry.location.kind === "extras"
                            ? "ri-checkbox-multiple-line"
                            : entry.location.kind === "habitation"
                                ? "ri-home-gear-line"
                                : "ri-file-list-3-line";
                        return (
                            <button
                                key={`${entry.label}-${i}`}
                                type="button"
                                role="option"
                                aria-selected={isActive}
                                onMouseEnter={() => setActiveIndex(i)}
                                onClick={() => selectEntry(entry)}
                                className="d-flex align-items-center w-100 text-start border-0 px-3"
                                style={{
                                    minHeight: 48,
                                    background: isActive ? "#f1f5f9" : "transparent",
                                    cursor: "pointer",
                                    gap: 12,
                                }}
                            >
                                <i
                                    className={`${kindIcon} text-muted`}
                                    style={{ fontSize: 16, flexShrink: 0 }}
                                    aria-hidden="true"
                                />
                                <span style={{ minWidth: 0, flex: 1 }}>
                                    <span className="d-block fw-medium text-body text-truncate">
                                        {entry.label}
                                    </span>
                                    <span className="d-block text-muted fs-12 text-truncate">
                                        {sectionLabel}
                                    </span>
                                </span>
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
};

export default FormSearchBar;
