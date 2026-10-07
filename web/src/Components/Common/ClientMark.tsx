/**
 * A marca de um cliente: o logótipo, ou as iniciais num círculo de cor estável (a mesma
 * empresa tem sempre a mesma cor). Usada no seletor "A trabalhar em", na vista da agência
 * e no painel por cliente.
 */
const COLORS = ["#405189", "#0ab39c", "#f06548", "#299cdb", "#f7b84b", "#6559cc", "#e83e8c", "#3577f1", "#20c997", "#fd7e14"];

export const initials = (name: string): string => {
    const words = name.replace(/[^0-9A-Za-zÀ-ÿ\s]/g, " ").split(/\s+/).filter(Boolean);
    return ((words[0]?.[0] ?? "?") + (words[1]?.[0] ?? words[0]?.[1] ?? "")).toUpperCase();
};

export const clientColor = (name: string) => COLORS[name.split("").reduce((h, c) => (h * 31 + c.charCodeAt(0)) >>> 0, 7) % COLORS.length];

type Props = { name: string; logoPath?: string | null; size?: number; title?: string; className?: string };

export default function ClientMark({ name, logoPath, size = 22, title, className = "" }: Props) {
    const style = { width: size, height: size, minWidth: size, fontSize: Math.max(9, Math.round(size * 0.42)) };
    if (logoPath) {
        return <img src={`${process.env.REACT_APP_PUBLIC_URL ?? ""}${logoPath}`} alt="" title={title ?? name} className={`rounded-circle object-fit-cover ${className}`} style={style} />;
    }
    return (
        <span className={`rounded-circle d-inline-flex align-items-center justify-content-center fw-semibold text-white ${className}`} title={title ?? name} aria-hidden
            style={{ ...style, background: clientColor(name), lineHeight: 1 }}>{initials(name)}</span>
    );
}
