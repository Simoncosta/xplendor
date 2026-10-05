import { useCallback, useEffect, useRef, useState } from "react";

/**
 * Pedido à IA em fila, sem prender o ecrã: envia (start) ou retoma um pedido já existente
 * (resume), e consulta o estado enquanto estiver pendente. Nunca fica a rodar para sempre:
 * o servidor marca o pedido como parado ao fim de 3 minutos ("stalled") e a consulta passa
 * a ser mais espaçada; um erro do servidor também termina a espera. O pedido continua no
 * servidor mesmo que o utilizador saia da página (o resultado fica à espera e há aviso no sino).
 * Partilhado pelos assistentes (perfil, criativo, ideias, blog).
 */
const FAST_MS = 2000;
const SLOW_MS = 15000;

type Status = "queued" | "processing" | "done" | "error";
export type AiPollable = { id: number; status: Status; stalled?: boolean; stalled_message?: string | null; error_message?: string | null };

const pending = (s: Status | undefined) => s === "queued" || s === "processing";

export function useAiRequestPoll<T extends AiPollable>(fetchOne: (id: number) => Promise<any>) {
    const [data, setData] = useState<T | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const alive = useRef(true);

    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            if (timer.current) clearTimeout(timer.current);
        };
    }, []);

    const poll = useCallback((id: number, slow: boolean) => {
        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(async () => {
            try {
                const r: any = await fetchOne(id);
                if (!alive.current) return;
                const d: T = r.data;
                setData(d);
                if (pending(d.status)) poll(id, !!d.stalled);
            } catch {
                // Falha de rede momentânea: tenta outra vez, mais devagar.
                if (alive.current) poll(id, true);
            }
        }, slow ? SLOW_MS : FAST_MS);
    }, [fetchOne]);

    /** Retoma um pedido existente (ex.: o que ficou à espera ao voltar à página). */
    const resume = useCallback((d: T) => {
        setData(d);
        if (pending(d.status)) poll(d.id, !!d.stalled);
    }, [poll]);

    /** Envia o pedido (a função devolve o pedido criado) e começa a consultar. */
    const start = useCallback(async (create: () => Promise<any>) => {
        if (timer.current) clearTimeout(timer.current);
        setData(null);
        const r: any = await create();
        resume(r.data as T);
    }, [resume]);

    const reset = useCallback(() => {
        if (timer.current) clearTimeout(timer.current);
        setData(null);
    }, []);

    const busy = pending(data?.status);
    const stalled = busy && !!data?.stalled;

    return { data, busy, stalled, timedOut: stalled, start, resume, reset };
}
