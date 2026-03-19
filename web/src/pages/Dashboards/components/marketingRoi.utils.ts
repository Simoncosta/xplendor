export const formatCurrency = (value?: number | null) => {
    const amount = Number(value || 0);

    return amount.toLocaleString("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 2,
    });
};

export const formatCurrencyWithFallback = (value?: number | null) => {
    if (!value || value <= 0) {
        return "—";
    }

    return value.toLocaleString("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 2,
    });
};

export const formatPercent = (value?: number | null) => {
    return `${Number(value || 0).toFixed(2)}%`;
};

export const formatNumber = (value?: number | null) => {
    return Number(value || 0).toLocaleString("pt-PT");
};
