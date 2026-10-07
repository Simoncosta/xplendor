/**
 * "O que a pessoa escreve nunca se perde": junta o rascunho do ecrã com os dados que chegam
 * do servidor. Um campo só passa a ter o valor do servidor se a pessoa não o alterou (igual
 * ao último valor do servidor) ou se é exatamente o que acabou de ser gravado (o servidor pode
 * devolvê-lo normalizado). O resto fica como a pessoa o escreveu.
 */

const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);

export function mergeDraft<T extends Record<string, unknown>>(draft: T, base: T, server: T, sent?: Partial<T> | null): T {
    const next = { ...draft };
    (Object.keys(server) as (keyof T)[]).forEach((k) => {
        const untouched = same(draft[k], base[k]);
        const justSaved = !!sent && k in sent && same(draft[k], sent[k]);
        if (untouched || justSaved) next[k] = server[k];
    });
    return next;
}

/** Os campos alterados em relação ao último valor do servidor. */
export function dirtyKeys<T extends Record<string, unknown>>(draft: T, base: T): (keyof T)[] {
    return (Object.keys(base) as (keyof T)[]).filter((k) => !same(draft[k], base[k]));
}
