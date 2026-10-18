import { FICHA } from "@/content/textos";
import { cn } from "@/lib/cn";

/** A classe energética bem visível: a letra grande na cor da escala oficial (contraste AA para texto grande). */
const COLORS: Record<string, [string, string]> = {
    "A+": ["#0B6B3A", "#FFFFFF"], A: ["#1E7B34", "#FFFFFF"], B: ["#3F7F24", "#FFFFFF"], "B-": ["#8FB33A", "#0E1E37"],
    C: ["#E3C318", "#0E1E37"], D: ["#F2A60C", "#0E1E37"], E: ["#B34714", "#FFFFFF"], F: ["#A31D1D", "#FFFFFF"],
};

export function EnergyLabel({ value, precertificate, size = "lg", className }: { value: string | null; precertificate?: boolean; size?: "sm" | "lg"; className?: string }) {
    if (!value || value === "em_processo") return null;
    const exempt = value === "isento";
    const [bg, fg] = COLORS[value] ?? ["transparent", "currentColor"];
    const text = exempt ? FICHA.energiaIsento : value;
    return (
        <span className={cn("inline-flex items-center gap-3", className)}>
            <span className={cn("inline-flex items-center justify-center font-sans font-extrabold leading-none",
                size === "lg" ? "h-14 min-w-14 px-3 text-[1.9rem]" : "h-8 min-w-8 px-2 text-base", exempt && "border border-current text-[0.9rem] font-semibold")}
                style={exempt ? undefined : { background: bg, color: fg, clipPath: "polygon(0 0, 82% 0, 100% 50%, 82% 100%, 0 100%)", paddingRight: size === "lg" ? "1.4rem" : "0.9rem" }}
                aria-hidden="true">{text}</span>
            <span className={size === "lg" ? "text-sm leading-tight" : "sr-only"}>
                {FICHA.energia}{size === "lg" && <br />}<span className={size === "lg" ? "muted" : ""}>{exempt ? FICHA.energiaIsento : `${FICHA.classe} ${value}`}{precertificate ? `, ${FICHA.preCertificado}` : ""}</span>
            </span>
        </span>
    );
}
