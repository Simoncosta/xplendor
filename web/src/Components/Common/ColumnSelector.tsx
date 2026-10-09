import { useRef, useState } from "react";
import { Dropdown, DropdownToggle, DropdownMenu } from "reactstrap";

/**
 * XPLENDOR — ColumnSelector: escolha de colunas GENÉRICA e reutilizável. Não sabe nada do
 * contexto (fornecedores/artigos/...): recebe colunas abstratas e devolve escolhas. Não
 * persiste — o estado vive em quem o usa. Botão "Colunas" → popover com TODAS as colunas
 * (marcadas = visíveis), toggle individual e "Repor" para voltar às defaults. Dark-safe.
 */

export type Column = {
    id: string;
    label: string;
    visible: boolean;
    /** Coluna ainda sem dados: aparece desativada, com o motivo (não se pode mostrar). */
    disabledReason?: string | null;
};

export default function ColumnSelector({
    columns,
    onChange,
    defaults,
    buttonLabel = "Colunas",
    size = "sm",
    onReset,
}: {
    columns: Column[];
    onChange: (id: string, visible: boolean) => void;
    defaults?: string[];               // ids visíveis por default → habilita "Repor"
    /** Repor (quando quem usa guarda a escolha e prefere esquecê-la de uma vez). */
    onReset?: () => void;
    buttonLabel?: string;
    size?: "sm" | "lg";
}) {
    const [open, setOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);
    const toggleRef = useRef<HTMLButtonElement>(null);

    // Toggle simples: ignora cliques DENTRO do menu (portalado); fecha pelo botão, por clique
    // fora ou pelo Esc. Assim marcar e desmarcar colunas não fecha o menu.
    const toggle = (e?: any) => {
        // Só os CLIQUES dentro do menu não o fecham; o Esc fecha sempre (mesmo com o foco lá dentro).
        if (e && e.type === "click" && menuRef.current && e.target instanceof Node && menuRef.current.contains(e.target)) return;
        setOpen((o) => !o);
    };

    const reset = () => {
        if (onReset) { onReset(); return; }
        if (!defaults) return;
        for (const c of columns) {
            const should = defaults.includes(c.id);
            if (c.visible !== should) onChange(c.id, should);
        }
    };

    return (
        <Dropdown isOpen={open} toggle={toggle}>
            <DropdownToggle color="outline-primary" size={size} caret data-testid="columns-toggle" innerRef={toggleRef}>
                <i className="ri-layout-column-line me-1" />{buttonLabel}
            </DropdownToggle>
            {/* Portal (container=body) + strategy=fixed → não é cortado por overflow nem fica
                atrás do cabeçalho; flip automático (abre p/ cima se não houver espaço em baixo).
                Flex-column com max-height: itens rolam, título e "Repor" ficam fixos. */}
            <DropdownMenu
                end
                container="body"
                strategy="fixed"
                className="p-0 shadow"
                style={{ minWidth: 220, maxHeight: "min(70vh, calc(100vh - 96px))", overflow: "hidden", zIndex: 1056 }}
            >
                {/* flex-column no wrapper INTERNO (não no menu) — não sobrepõe o display:none
                    do reactstrap; herda o max-height do menu para o scroll interno funcionar. */}
                <div ref={menuRef} className="d-flex flex-column" style={{ minHeight: 0, maxHeight: "min(70vh, calc(100vh - 96px))" }}
                    onKeyDown={(e) => {
                        // O menu abre no body: o Esc não chega ao Dropdown. Fecha aqui e devolve o foco ao botão.
                        if (e.key === "Escape") { e.stopPropagation(); setOpen(false); toggleRef.current?.focus(); }
                    }}>
                    <div className="text-muted text-uppercase fw-semibold px-3 pt-2 pb-1 flex-shrink-0"
                        style={{ fontSize: "0.68rem", letterSpacing: "0.05em" }}>
                        Colunas visíveis
                    </div>

                    <div className="px-2 py-1" style={{ overflowY: "auto", flex: "1 1 auto", minHeight: 0 }}>
                        {columns.map((c) => (
                        <label
                            key={c.id}
                            className="d-flex align-items-start gap-2 rounded"
                            style={{ cursor: c.disabledReason ? "not-allowed" : "pointer", padding: "3px 8px" }}
                            onMouseEnter={(e) => (e.currentTarget.style.background = "var(--vz-tertiary-bg)")}
                            onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}
                        >
                            <input
                                type="checkbox"
                                className="form-check-input mt-1"
                                checked={c.visible && !c.disabledReason}
                                disabled={!!c.disabledReason}
                                data-testid={`column-${c.id}`}
                                onChange={(e) => onChange(c.id, e.target.checked)}
                            />
                            <span className="flex-grow-1">
                                <span className={c.disabledReason ? "text-muted" : undefined}>{c.label}</span>
                                {c.disabledReason && <span className="d-block fs-11 text-muted" style={{ maxWidth: 240, whiteSpace: "normal" }}>{c.disabledReason}</span>}
                            </span>
                        </label>
                    ))}
                </div>

                    {(defaults || onReset) && (
                        <div className="border-top p-2 flex-shrink-0">
                            <button type="button" className="btn btn-sm btn-outline-primary w-100" onClick={reset} data-testid="columns-reset">
                                <i className="ri-refresh-line me-1" />Repor colunas
                            </button>
                        </div>
                    )}
                </div>
            </DropdownMenu>
        </Dropdown>
    );
}
