import Image from "next/image";

/**
 * XPLENDOR — Mockup de janela de browser (barra + 3 pontos + ecrã).
 * Portado do LandingX (CRA), adaptado aos tokens do Rayo (dark-mode-safe).
 * O ecrã tem rácio 16:9 (igual aos screenshots da plataforma). Sem `src`, mostra
 * um estado "em breve" (placeholder) para os casos ainda sem screenshot real.
 */
export default function BrowserMockup({
  src,
  alt = "",
  width = 1490,
  height = 845,
  emptyLabel = "Screenshot em breve",
}: {
  src?: string;
  alt?: string;
  width?: number;
  height?: number;
  emptyLabel?: string;
}) {
  return (
    <div className="xp-mockup">
      <div className="xp-mockup__bar" aria-hidden="true">
        <span />
        <span />
        <span />
      </div>
      <div className="xp-mockup__screen">
        {src ? (
          <Image src={src} alt={alt} width={width} height={height} />
        ) : (
          <div className="xp-mockup__empty" aria-hidden="true">
            <i className="ph-bold ph-image" />
            <span>{emptyLabel}</span>
          </div>
        )}
      </div>
    </div>
  );
}
