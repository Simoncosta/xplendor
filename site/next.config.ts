import type { NextConfig } from "next";

/**
 * XPLENDOR — Rayo (landing) exportado ESTÁTICO, servido pelo nginx como ficheiros
 * (Opção A, sem container Node). Rayo na RAIZ (/): SEM basePath → os assets resolvem em
 * /_next/… ; a app CRA vive em /app. images.unoptimized é obrigatório no export estático
 * (sem otimizador Node). O type-check fica LIGADO (rede de segurança) — o único quirk do
 * gsap (casing) é tratado no tsconfig via forceConsistentCasingInFileNames.
 */
const nextConfig: NextConfig = {
  output: "export",
  trailingSlash: true,
  images: { unoptimized: true },
};

export default nextConfig;
