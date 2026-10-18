/** A primeira frase da biografia (a segunda linha de "Quem sou"); o resto fica na página Sobre. */
export function firstSentence(text: string | null): string | null {
    const t = (text ?? "").trim().replace(/\s+/g, " ");
    if (!t) return null;
    const m = t.match(/^.+?[.!?](?=\s|$)/);
    return (m ? m[0] : t).slice(0, 180);
}

/** "União das freguesias de Aldoar, Foz do Douro e Nevogilde" → "Aldoar, Foz do Douro e Nevogilde". */
export const shortParish = (name: string) => name.replace(/^União das freguesias d[eoa]s?\s+/i, "");
