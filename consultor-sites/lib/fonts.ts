import localFont from "next/font/local";

/** Fraunces (títulos, com tamanho ótico) e Manrope (texto), alojadas no próprio site (@fontsource), sem pedidos ao Google. */
export const fraunces = localFont({
    src: "../node_modules/@fontsource-variable/fraunces/files/fraunces-latin-opsz-normal.woff2",
    variable: "--font-fraunces",
    weight: "100 900",
    display: "swap",
    adjustFontFallback: "Times New Roman",
});

export const manrope = localFont({
    src: "../node_modules/@fontsource-variable/manrope/files/manrope-latin-wght-normal.woff2",
    variable: "--font-manrope",
    weight: "200 800",
    display: "swap",
    adjustFontFallback: "Arial",
});
