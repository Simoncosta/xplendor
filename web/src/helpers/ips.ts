// IPS (Índice de Potencial de Venda) — formatação centralizada do badge.
//
// Estado "a calibrar" (pending): score === null OU classification === 'pending'
// significa que ainda não há sinais suficientes (sem visitas e sem dados de
// mercado). Mostra-se texto neutro (cinza), NUNCA "0/100 · precisa de atenção".

export interface IpsBadge {
    /** Texto completo (ex: "72/100" ou "A calibrar · aguarda primeiras visitas"). */
    label: string;
    /** Texto compacto para badges pequenos (ex: "72" ou "Em recolha"). */
    shortLabel: string;
    /** Classes Bootstrap do badge. */
    className: string;
    /** Cor (hex) para gráficos. */
    color: string;
    /** Rótulo executivo (ex: "Probabilidade forte"). */
    executiveLabel: string;
    isPending: boolean;
}

export function formatIpsBadge(
    score: number | null | undefined,
    classification: string | null | undefined,
): IpsBadge {
    const isPending = score === null || score === undefined || classification === "pending";

    if (isPending) {
        return {
            label: "A calibrar · aguarda primeiras visitas",
            shortLabel: "Em recolha",
            className: "bg-light text-muted",
            color: "#e9ebec",
            executiveLabel: "A calibrar · aguarda primeiras visitas",
            isPending: true,
        };
    }

    const cls = classification ?? "cold";
    const className =
        cls === "hot"
            ? "bg-success-subtle text-success"
            : cls === "warm"
                ? "bg-warning-subtle text-warning"
                : "bg-danger-subtle text-danger";

    const color = score >= 70 ? "#0ab39c" : score >= 40 ? "#f7b84b" : "#f06548";

    const executiveLabel =
        cls === "hot"
            ? "Probabilidade forte"
            : cls === "warm"
                ? "Acompanhar esta semana"
                : "Precisa de atenção agora";

    return {
        label: `${score}/100`,
        shortLabel: String(score),
        className,
        color,
        executiveLabel,
        isPending: false,
    };
}
