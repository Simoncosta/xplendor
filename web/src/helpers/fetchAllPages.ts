/**
 * Lê todas as páginas de uma lista paginada pelo Laravel (para o DataTable em modo cliente,
 * que ordena, pesquisa e pagina no browser). Para quando a lista é pequena (poucas centenas
 * de registos). Devolve as linhas e a resposta da primeira página (para ler o resto: data da
 * última sincronização, facetas, etc.).
 *
 *   const { rows, first } = await fetchAllPages((page) => getPingwinUnits(id, { ...params, page, perPage: 200 }), (r) => r?.data?.units);
 */
export async function fetchAllPages<T>(
    fetchPage: (page: number) => Promise<any>,
    pick: (response: any) => { data?: T[]; last_page?: number } | null | undefined,
    maxPages = 25,
): Promise<{ rows: T[]; first: any }> {
    const first = await fetchPage(1);
    const paginator = pick(first);
    let rows: T[] = paginator?.data ?? [];
    const last = Math.min(paginator?.last_page ?? 1, maxPages);
    for (let p = 2; p <= last; p++) {
        const next = await fetchPage(p);
        rows = rows.concat(pick(next)?.data ?? []);
    }
    return { rows, first };
}
