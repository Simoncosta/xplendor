import React from "react";

type CarPriceDisplayProps = {
    priceGross?: number | string | null;
    promoPriceGross?: number | string | null;
    promoDiscountPct?: number | string | null;
    align?: "start" | "center" | "end";
    size?: "sm" | "md" | "lg";
    badgeLabel?: string;
    className?: string;
};

const formatPrice = (value?: number | string | null) => {
    const parsed = Number(value ?? 0);

    return new Intl.NumberFormat("pt-PT", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(parsed);
};

export default function CarPriceDisplay({
    priceGross,
    promoPriceGross,
    promoDiscountPct,
    align = "start",
    size = "md",
    badgeLabel = "Em promoção",
    className = "",
}: CarPriceDisplayProps) {
    const basePrice = Number(priceGross ?? 0);
    const promoPrice = Number(promoPriceGross ?? 0);
    const hasPromotion = Number.isFinite(basePrice)
        && Number.isFinite(promoPrice)
        && basePrice > 0
        && promoPrice > 0
        && promoPrice < basePrice;

    const sizeClass = size === "lg"
        ? "fs-24"
        : size === "sm"
            ? "fs-14"
            : "fs-18";

    const justifyClass = align === "center"
        ? "align-items-center text-center"
        : align === "end"
            ? "align-items-end text-end"
            : "align-items-start text-start";

    return (
        <div className={`d-flex flex-column gap-1 ${justifyClass} ${className}`.trim()}>
            {hasPromotion && (
                <div className="d-flex align-items-center gap-2 flex-wrap">
                    <span className="text-muted text-decoration-line-through fs-13">
                        €{formatPrice(basePrice)}
                    </span>
                    <span className="badge bg-danger-subtle text-danger">
                        {badgeLabel}
                        {promoDiscountPct ? ` · -${Math.round(Number(promoDiscountPct))}%` : ""}
                    </span>
                </div>
            )}

            <div className={`${sizeClass} fw-bold ${hasPromotion ? "text-danger" : "text-body"}`}>
                €{formatPrice(hasPromotion ? promoPrice : basePrice)}
            </div>
        </div>
    );
}
