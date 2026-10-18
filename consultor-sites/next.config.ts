import type { NextConfig } from "next";

/**
 * XPLENDOR: o site dos consultores imobiliários. Modo servidor (standalone, num contentor
 * próprio), um só serviço para todos os consultores: o consultor vem do host do pedido.
 * As imagens chegam já nas larguras certas da XPLENDOR (srcset), sem o otimizador do Next.
 */
const nextConfig: NextConfig = {
    output: "standalone",
    poweredByHeader: false,
    images: { unoptimized: true },
    async headers() {
        return [{
            source: "/:path*",
            headers: [
                { key: "X-Content-Type-Options", value: "nosniff" },
                { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
                { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=(), interest-cohort=()" },
                { key: "Content-Security-Policy", value: "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'" },
            ],
        }];
    },
};

export default nextConfig;
