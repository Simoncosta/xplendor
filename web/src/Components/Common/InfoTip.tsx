import React, { useId, useState } from "react";
import { Tooltip } from "reactstrap";

/**
 * O ícone (i) com a explicação curta de uma página ou de um cartão (documents/design-system.md
 * §3). Substitui as frases soltas: a explicação abre ao passar o rato, ao focar com o teclado e
 * ao tocar (telemóvel). É um botão, por isso tem nome para leitores de ecrã.
 */
type Props = {
    /** A explicação (curta: uma ou duas frases). */
    text: React.ReactNode;
    /** Nome para leitores de ecrã (por omissão "Sobre esta página"). */
    label?: string;
    placement?: "top" | "bottom" | "left" | "right" | "auto";
    className?: string;
};

export default function InfoTip({ text, label = "Sobre esta página", placement = "bottom", className = "" }: Props) {
    const id = `info-${useId().replace(/:/g, "")}`;
    const [open, setOpen] = useState(false);
    return (
        <>
            <button
                type="button"
                id={id}
                className={`xp-infotip ${className}`}
                aria-label={label}
                aria-expanded={open}
                data-testid="infotip"
                onMouseEnter={() => setOpen(true)}
                onMouseLeave={() => setOpen(false)}
                onFocus={() => setOpen(true)}
                onBlur={() => setOpen(false)}
                // No toque, o foco e o clique chegam juntos: o clique só ABRE (fecha-se ao tocar fora).
                onClick={(e) => { e.stopPropagation(); setOpen(true); }}
                onKeyDown={(e) => { if (e.key === "Escape") setOpen(false); }}
            >
                <i className="ri-information-line" aria-hidden="true" />
            </button>
            <Tooltip target={id} isOpen={open} trigger="manual" placement={placement} innerClassName="xp-infotip-text" fade={false}>
                {text}
            </Tooltip>
        </>
    );
}
