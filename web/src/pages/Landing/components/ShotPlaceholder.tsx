import React from 'react';

interface ShotPlaceholderProps {
    /** Descrição da captura que vai entrar aqui (para o Simon saber o que meter). */
    label: string;
    /** Rácio de aspeto (ex.: '16 / 10'). Default 16/10. */
    ratio?: string;
    /** src real da imagem — quando definido, mostra a imagem em vez do placeholder. */
    src?: string;
    alt?: string;
    /** Moldura de browser à volta (para parecer um screenshot de app). */
    framed?: boolean;
}

/**
 * Placeholder CLARAMENTE marcado para as capturas reais do produto. Enquanto
 * `src` não for passado, mostra uma moldura com ícone + descrição do que entra.
 * Quando o Simon tiver as capturas, passa `src` e a imagem substitui o placeholder.
 */
const ShotPlaceholder: React.FC<ShotPlaceholderProps> = ({
    label,
    ratio = '16 / 10',
    src,
    alt,
    framed = true,
}) => {
    const inner = src ? (
        <img src={src} alt={alt ?? label} className="lp-shot-img" style={{ aspectRatio: ratio }} />
    ) : (
        <div className="lp-shot-ph" style={{ aspectRatio: ratio }}>
            <i className="ri-image-add-line" aria-hidden="true" />
            <span className="lp-shot-ph-tag">Captura real entra aqui</span>
            <span className="lp-shot-ph-label">{label}</span>
        </div>
    );

    if (!framed) return inner;

    return (
        <div className="lp-shot-frame" aria-hidden={src ? undefined : true}>
            <div className="lp-shot-bar">
                <span /><span /><span />
            </div>
            {inner}
        </div>
    );
};

export default ShotPlaceholder;
