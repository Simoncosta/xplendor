import { useCallback, useEffect, useRef, useState } from "react";

/**
 * Pedido à IA em fila: envia, consulta o estado até "done" ou "error" (com limite de
 * tentativas) e devolve o último estado. Partilhado pelos assistentes (perfil, criativo).
 */
const POLL_MS = 2000;
const POLL_LIMIT = 60;

type Status = "queued" | "processing" | "done" | "error";

export function useAiRequestPoll<T extends { id: number; status: Status }>(
    fetchOne: (id: number) => Promise<any>,
) {
    const [data, setData] = useState<T | null>(null);
    const [busy, setBusy] = useState(false);
    const [timedOut, setTimedOut] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const alive = useRef(true);

    useEffect(() => () => {
        alive.current = false;
        if (timer.current) clearTimeout(timer.current);
    }, []);

    const poll = useCallback((id: number, attempt = 0) => {
        timer.current = setTimeout(async () => {
            try {
                const r: any = await fetchOne(id);
                if (!alive.current) return;
                const d: T = r.data;
                setData(d);
                if (d.status === "done" || d.status === "error") {
                    setBusy(false);
                    return;
                }
                if (attempt >= POLL_LIMIT) {
                    setBusy(false);
                    setTimedOut(true);
                    return;
                }
                poll(id, attempt + 1);
            } catch {
                if (alive.current) setBusy(false);
            }
        }, POLL_MS);
    }, [fetchOne]);

    /** Envia o pedido (a função devolve o pedido criado) e começa a consultar. */
    const start = useCallback(async (create: () => Promise<any>) => {
        if (timer.current) clearTimeout(timer.current);
        setBusy(true);
        setTimedOut(false);
        setData(null);
        try {
            const r: any = await create();
            const d: T = r.data;
            setData(d);
            if (d.status === "done" || d.status === "error") {
                setBusy(false);
            } else {
                poll(d.id);
            }
        } catch (e) {
            setBusy(false);
            throw e;
        }
    }, [poll]);

    const reset = useCallback(() => {
        if (timer.current) clearTimeout(timer.current);
        setData(null);
        setBusy(false);
        setTimedOut(false);
    }, []);

    return { data, busy, timedOut, start, reset };
}
