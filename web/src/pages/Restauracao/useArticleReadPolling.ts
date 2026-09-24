import { useCallback, useState } from "react";

/**
 * XPLENDOR — LEITURA de artigos do PingWin com POLLING (espelho do write).
 *
 * ⚠️ Opção 1: as leituras (form-lookups / readProduct) correm no worker (root) —
 * o www-data (php-fpm) nunca faz docker exec. O endpoint devolve um `read_id`
 * (token) e o resultado fica em Redis; fazemos polling até `ready`/`error`.
 * Leituras são idempotentes → o backend faz retry; aqui só esperamos.
 *
 * Uso:
 *   const { loading, read } = useArticleReadPolling(companyId);
 *   const r = await read(
 *     () => getArticleFormLookups(companyId),      // dispara → { read_id }
 *     (t) => getArticleRead(companyId, t),          // poll do token
 *   );
 *   if (r.status === "ready") { const form = r.data.form } else toast.error(r.error_message)
 */

const sleep = (ms: number) => new Promise((res) => setTimeout(res, ms));

export type ReadResult = { status: "ready" | "error"; data?: any; error_message?: string };

export function useArticleReadPolling(companyId: number) {
    const [loading, setLoading] = useState(false);

    const read = useCallback(
        async (
            start: () => Promise<any>,
            poll: (token: string) => Promise<any>,
            opts?: { timeoutMs?: number; intervalMs?: number }
        ): Promise<ReadResult> => {
            const timeoutMs = opts?.timeoutMs ?? 60000;
            const intervalMs = opts?.intervalMs ?? 1200;
            setLoading(true);
            try {
                const res: any = await start();
                const token = res?.data?.read_id;
                if (!token) {
                    return { status: "error", error_message: "O servidor não devolveu um token de leitura." };
                }
                const t0 = Date.now();
                while (Date.now() - t0 < timeoutMs) {
                    await sleep(intervalMs);
                    let p: any;
                    try {
                        p = await poll(token);
                    } catch {
                        continue; // falha transitória do poll → tenta de novo
                    }
                    const st = p?.data?.status;
                    if (st === "ready") return { status: "ready", data: p.data };
                    if (st === "error") return { status: "error", error_message: p?.data?.error_message };
                    // pending → continua
                }
                return { status: "error", error_message: "Tempo esgotado a carregar do PingWin. Tenta novamente." };
            } catch (e: any) {
                return { status: "error", error_message: e?.message ?? "Falha ao contactar o servidor." };
            } finally {
                setLoading(false);
            }
        },
        []
    );

    return { loading, read };
}
