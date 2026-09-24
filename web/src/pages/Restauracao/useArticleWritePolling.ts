import { useCallback, useState } from "react";

/**
 * XPLENDOR — Escrita de artigos no PingWin com POLLING do resultado REAL.
 *
 * A escrita (criar/editar/anular) corre no worker e devolve um `creation_id`
 * (linha pingwin_catalog_writes). NÃO há sucesso otimista: fazemos polling da
 * linha até status `ok` ou `erro` — o mesmo padrão das Unidades. Só o estado
 * REAL do servidor conta (mensagem de sucesso ≠ gravado).
 *
 * Uso:
 *   const { busy, submit } = useArticleWritePolling(companyId);
 *   const r = await submit(
 *     () => createArticle(companyId, data),          // faz o POST/PUT/DELETE
 *     (id) => getArticleCreation(companyId, id),      // faz o poll da linha
 *   );
 *   if (r.status === "ok") { ... } else { toast.error(r.row?.error_message) }
 */

const sleep = (ms: number) => new Promise((res) => setTimeout(res, ms));

export type WriteResult = { status: "ok" | "erro"; row: any };

export function useArticleWritePolling(companyId: number) {
    const [busy, setBusy] = useState(false);

    const submit = useCallback(
        async (
            write: () => Promise<any>,
            poll: (creationId: number) => Promise<any>,
            opts?: { timeoutMs?: number; intervalMs?: number }
        ): Promise<WriteResult> => {
            const timeoutMs = opts?.timeoutMs ?? 120000;
            const intervalMs = opts?.intervalMs ?? 1500;
            setBusy(true);
            try {
                const res: any = await write();
                const creationId = res?.data?.creation_id;
                if (!creationId) {
                    return { status: "erro", row: { error_message: "O servidor não devolveu um id de operação." } };
                }
                const start = Date.now();
                while (Date.now() - start < timeoutMs) {
                    await sleep(intervalMs);
                    let p: any;
                    try {
                        p = await poll(creationId);
                    } catch {
                        continue; // falha transitória do poll → tenta de novo
                    }
                    const st = p?.data?.status;
                    if (st === "ok") return { status: "ok", row: p.data };
                    if (st === "erro") return { status: "erro", row: p.data };
                    // a_criar/a_editar/a_anular → continua a esperar
                }
                return { status: "erro", row: { error_message: "Tempo esgotado à espera do PingWin. Verifica no sino/lista." } };
            } catch (e: any) {
                return { status: "erro", row: { error_message: e?.message ?? "Falha ao contactar o servidor." } };
            } finally {
                setBusy(false);
            }
        },
        // companyId é usado pelas closures write/poll passadas; não é dep direto aqui.
        []
    );

    return { busy, submit };
}
