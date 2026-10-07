import { useEffect, useState } from "react";
import { getCompanyManagement } from "helpers/laravel_helper";

/**
 * Quem produz para a empresa no modo "Produção pela equipa", para os textos da Linha
 * Editorial: "a sua agência (Nome)" do lado do cliente, "a agência Nome" para quem trabalha
 * pela agência, ou "a equipa XPLENDOR" numa empresa sem agência. Um pedido por empresa.
 */
const cache = new Map<number, Promise<string>>();
const FALLBACK = "a equipa que produz";

export default function useProducerLabel(companyId: number): string {
    const [label, setLabel] = useState(FALLBACK);
    useEffect(() => {
        if (!companyId) return;
        if (!cache.has(companyId)) {
            cache.set(companyId, getCompanyManagement(companyId).then((r: any) => String(r?.data?.producer_label || FALLBACK)).catch(() => FALLBACK));
        }
        let alive = true;
        cache.get(companyId)!.then((l) => { if (alive) setLabel(l); });
        return () => { alive = false; };
    }, [companyId]);

    return label;
}

/** "A sua agência (Nome)" no início de uma frase. */
export const capitalize = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);
/** "pela sua agência (Nome)": a contração de "por" com o artigo. */
export const byProducer = (label: string) => `pel${label}`;
