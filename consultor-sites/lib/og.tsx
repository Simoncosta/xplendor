import { readFile } from "node:fs/promises";
import { join } from "node:path";
import { ImageResponse } from "next/og";

/**
 * A imagem de partilha de um anúncio (1200 × 630): a fotografia de capa, o título, uma linha de
 * factos e o nome do consultor, nas fontes do site (ficheiros .woff do próprio site). Sai em JPEG.
 */
export const OG_SIZE = { width: 1200, height: 630 };

async function font(pkg: string, file: string) {
    return readFile(join(process.cwd(), "node_modules", "@fontsource", pkg, "files", file));
}

/**
 * A capa em JPEG, já no tamanho do quadro: as fotografias do site são WebP e o gerador destas
 * imagens não lê WebP. Se a capa não chegar ou não se converter, a imagem sai sem fotografia.
 */
async function coverAsJpeg(src: string): Promise<string | null> {
    try {
        const res = await fetch(src, { cache: "no-store" });
        if (!res.ok) return null;
        const { default: sharp } = await import("sharp");
        const jpeg = await sharp(Buffer.from(await res.arrayBuffer())).resize(680, 630, { fit: "cover" }).jpeg({ quality: 82 }).toBuffer();
        return `data:image/jpeg;base64,${jpeg.toString("base64")}`;
    } catch {
        return null;
    }
}

export async function listingImage({ title, line, price, name, cover, colors, draft }: {
    title: string; line: string; price: string | null; name: string; cover: string | null;
    colors: { navy: string; cream: string; amber: string }; draft: boolean;
}) {
    const [display, sans, photo] = await Promise.all([
        font("fraunces", "fraunces-latin-500-normal.woff"), font("manrope", "manrope-latin-600-normal.woff"), cover ? coverAsJpeg(cover) : null,
    ]);
    const png = new ImageResponse(
        (
            <div style={{ width: "100%", height: "100%", display: "flex", background: colors.navy, color: colors.cream, fontFamily: "Manrope" }}>
                {photo && (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={photo} alt="" width={680} height={630} style={{ width: 680, height: 630, objectFit: "cover" }} />
                )}
                <div style={{ flex: 1, display: "flex", flexDirection: "column", justifyContent: "space-between", padding: "56px 52px" }}>
                    <div style={{ display: "flex", flexDirection: "column" }}>
                        {draft && <div style={{ display: "flex", alignSelf: "flex-start", background: colors.amber, color: colors.navy, padding: "6px 14px", fontSize: 22, marginBottom: 24 }}>RASCUNHO</div>}
                        <div style={{ fontFamily: "Fraunces", fontSize: photo ? 50 : 64, lineHeight: 1.08 }}>{title}</div>
                        <div style={{ marginTop: 22, fontSize: 24, opacity: 0.85 }}>{line}</div>
                    </div>
                    <div style={{ display: "flex", flexDirection: "column" }}>
                        {price && <div style={{ fontSize: 34, color: colors.amber }}>{price}</div>}
                        <div style={{ marginTop: 18, fontSize: 24 }}>{name}</div>
                    </div>
                </div>
            </div>
        ),
        { ...OG_SIZE, fonts: [{ name: "Fraunces", data: display, weight: 500, style: "normal" }, { name: "Manrope", data: sans, weight: 600, style: "normal" }] },
    );
    // Em JPEG: um PNG com fotografia passa de 1 MB, e o WhatsApp não mostra imagens assim tão pesadas.
    const { default: sharp } = await import("sharp");
    const jpeg = await sharp(Buffer.from(await png.arrayBuffer())).jpeg({ quality: 84, mozjpeg: true }).toBuffer();
    return new Response(new Uint8Array(jpeg), { headers: { "Content-Type": "image/jpeg", "Cache-Control": png.headers.get("Cache-Control") ?? "public, max-age=0, must-revalidate" } });
}
