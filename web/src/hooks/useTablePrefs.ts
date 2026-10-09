import { useCallback, useEffect, useState } from "react";

/**
 * As preferências de uma tabela (por agora, as colunas visíveis), guardadas no browser de cada
 * pessoa, por tabela (localStorage "xp.table.<key>"). Tudo passa por este hook para que, um dia,
 * as preferências possam ir para a conta sem mexer nas páginas.
 *
 * O armazenamento pode falhar (janela privada, quota, bloqueio): nesse caso a tabela funciona
 * na mesma, só não se lembra da escolha.
 */
export type TablePrefs = { columns?: Record<string, boolean> };

const PREFIX = "xp.table.";
const VERSION = 1;

function read(key: string): TablePrefs {
    try {
        const raw = window.localStorage.getItem(PREFIX + key);
        if (!raw) return {};
        const parsed = JSON.parse(raw);
        return parsed && parsed.v === VERSION && typeof parsed.prefs === "object" ? (parsed.prefs as TablePrefs) : {};
    } catch {
        return {};
    }
}

function write(key: string, prefs: TablePrefs) {
    try {
        window.localStorage.setItem(PREFIX + key, JSON.stringify({ v: VERSION, prefs }));
    } catch {
        /* sem armazenamento: a escolha vale só até recarregar */
    }
}

function remove(key: string) {
    try {
        window.localStorage.removeItem(PREFIX + key);
    } catch {
        /* idem */
    }
}

export default function useTablePrefs(key: string) {
    const [prefs, setPrefs] = useState<TablePrefs>(() => read(key));

    // Outra tabela com outra chave no mesmo componente: volta a ler.
    useEffect(() => { setPrefs(read(key)); }, [key]);

    /** Muda a visibilidade de uma coluna (e guarda). */
    const setColumn = useCallback((id: string, visible: boolean) => {
        setPrefs((cur) => {
            const next = { ...cur, columns: { ...(cur.columns ?? {}), [id]: visible } };
            write(key, next);
            return next;
        });
    }, [key]);

    /** "Repor colunas": esquece a escolha e volta às colunas por omissão. */
    const resetColumns = useCallback(() => {
        setPrefs((cur) => {
            const next = { ...cur, columns: undefined };
            if (Object.keys(next).every((k) => (next as any)[k] === undefined)) remove(key); else write(key, next);
            return next;
        });
    }, [key]);

    return { prefs, setColumn, resetColumns };
}
