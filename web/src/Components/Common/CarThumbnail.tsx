import { useState } from "react";

/**
 * Thumbnail de viatura partilhado — substitui o ícone partido do browser
 * quando `src=null` OU quando o carregamento da imagem falha (onError).
 *
 * Três variantes para os 3 padrões que existem na app:
 *
 *   "row"        — linha de tabela arejada (CarList, StockPromotion desktop):
 *                  largura fixa, aspect-ratio 16:9, radius 1rem, soft shadow.
 *                  Placeholder: silhueta `ri-car-line` + label "Sem imagem".
 *
 *   "compact"    — thumbnail inline pequeno (LeadList desktop/mobile):
 *                  width/height controlados pelo caller, `rounded` Bootstrap (4px),
 *                  sem sombra. Placeholder: só o ícone (label não cabe).
 *
 *   "fullwidth"  — mobile card edge-to-edge (CarList/PromotionMobileCard mobile):
 *                  estrutura `position:relative paddingBottom:56.25%` (16:9 fluido),
 *                  preenche toda a largura do pai. Placeholder centrado.
 *
 * `src` aceita URL completa OU caminho relativo do storage Laravel:
 *   - começa com "http"/"//" → assume absoluto, NÃO prefixa
 *   - caso contrário        → prefixa com REACT_APP_PUBLIC_URL
 *   Isto permite consumir directamente `car.images[0].image` (relativo) E
 *   o output de `getCarThumbnailUrl()` da CarList (absoluto, já considera
 *   external_url do scraper) sem dois call-sites diferentes.
 */
interface Props {
    src: string | null;
    variant?: "row" | "compact" | "fullwidth";
    /** Largura em pixels — só para "row" (default 150) e "compact" (default 50).
     *  Ignorado em "fullwidth" que ocupa o pai. */
    width?: number;
    /** Altura em pixels — só para "compact" (default 35). Em "row" deriva
     *  do aspect-ratio 16:9; em "fullwidth" idem. */
    height?: number;
    /** Class adicional para o wrapper — útil para `flex-shrink-0` ou `me-3`. */
    className?: string;
}

const resolveSrc = (src: string | null): string | null => {
    if (!src) return null;
    if (src.startsWith("http://") || src.startsWith("https://") || src.startsWith("//")) {
        return src;
    }
    return (process.env.REACT_APP_PUBLIC_URL || "") + src;
};

const CarThumbnail = ({ src, variant = "row", width, height, className }: Props) => {
    const [failed, setFailed] = useState(false);
    const showPlaceholder = !src || failed;
    const resolved = showPlaceholder ? null : resolveSrc(src);

    // ── Variant: row (linhas grandes de tabela) ───────────────────────────
    if (variant === "row") {
        const w = width ?? 150;
        const style: React.CSSProperties = {
            width: w,
            aspectRatio: "16 / 9",
            borderRadius: "1rem",
            flexShrink: 0,
            backgroundColor: showPlaceholder ? "#f1f3f5" : undefined,
            boxShadow: "0 8px 24px rgba(15, 23, 42, 0.08)",
            overflow: "hidden",
        };

        if (showPlaceholder) {
            return (
                <div
                    className={`d-flex flex-column align-items-center justify-content-center ${className ?? ""}`}
                    style={style}
                    aria-hidden="true"
                >
                    <i
                        className="ri-car-line"
                        style={{ fontSize: Math.round(w * 0.22), color: "#adb5bd", lineHeight: 1 }}
                    />
                    <span
                        className="fw-semibold text-uppercase mt-1"
                        style={{ fontSize: 10, color: "#adb5bd", letterSpacing: "0.06em" }}
                    >
                        Sem imagem
                    </span>
                </div>
            );
        }

        return (
            <img
                src={resolved as string}
                alt=""
                onError={() => setFailed(true)}
                className={className}
                style={{ ...style, objectFit: "cover" }}
            />
        );
    }

    // ── Variant: compact (inline pequeno em listas — LeadList) ────────────
    if (variant === "compact") {
        const w = width ?? 50;
        const h = height ?? 35;
        const style: React.CSSProperties = {
            width: w,
            height: h,
            flexShrink: 0,
            backgroundColor: showPlaceholder ? "#f1f3f5" : undefined,
            overflow: "hidden",
        };

        if (showPlaceholder) {
            return (
                <div
                    className={`rounded d-flex align-items-center justify-content-center ${className ?? ""}`}
                    style={style}
                    aria-hidden="true"
                >
                    <i
                        className="ri-car-line"
                        style={{ fontSize: Math.round(Math.min(w, h) * 0.5), color: "#adb5bd", lineHeight: 1 }}
                    />
                </div>
            );
        }

        return (
            <img
                src={resolved as string}
                alt=""
                onError={() => setFailed(true)}
                className={`rounded ${className ?? ""}`}
                style={{ ...style, objectFit: "cover" }}
            />
        );
    }

    // ── Variant: fullwidth (mobile cards edge-to-edge, aspect 16:9 fluido) ─
    const wrapperStyle: React.CSSProperties = {
        position: "relative",
        paddingBottom: "56.25%",
        background: "#f1f3f5",
    };

    if (showPlaceholder) {
        return (
            <div className={className} style={wrapperStyle}>
                <div
                    style={{
                        position: "absolute",
                        inset: 0,
                        display: "flex",
                        flexDirection: "column",
                        alignItems: "center",
                        justifyContent: "center",
                        gap: 4,
                    }}
                    aria-hidden="true"
                >
                    <i className="ri-car-line" style={{ fontSize: 32, color: "#adb5bd" }} />
                    <span
                        className="fw-semibold text-uppercase"
                        style={{ fontSize: 11, color: "#adb5bd", letterSpacing: "0.06em" }}
                    >
                        Sem imagem
                    </span>
                </div>
            </div>
        );
    }

    return (
        <div className={className} style={wrapperStyle}>
            <img
                src={resolved as string}
                alt=""
                onError={() => setFailed(true)}
                style={{
                    position: "absolute",
                    inset: 0,
                    width: "100%",
                    height: "100%",
                    objectFit: "cover",
                }}
            />
        </div>
    );
};

export default CarThumbnail;
