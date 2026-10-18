import { NextResponse, type NextRequest } from "next/server";
import { headers } from "next/headers";
import { checkPreview, PREVIEW_COOKIE } from "@/lib/api";
import { env } from "@/lib/env";
import { resolveHost } from "@/lib/host";

/**
 * Entrar na pré-visualização: o link assinado e temporário da XPLENDOR (?token=…). Se a XPLENDOR
 * o aceitar, fica num cookie necessário (xp_preview, 1 hora, só deste site) e segue para a
 * página inicial. Sem token válido, segue para a página inicial sem pré-visualização.
 */
export async function GET(req: NextRequest) {
    const token = req.nextUrl.searchParams.get("token") ?? "";
    const host = resolveHost((await headers()).get("host"), env.devHosts);
    const home = new URL("/", req.nextUrl);
    const res = NextResponse.redirect(home, 303);
    res.headers.set("X-Robots-Tag", "noindex, nofollow");
    if (token && host && (await checkPreview(host, token))) {
        res.cookies.set(PREVIEW_COOKIE, token, { httpOnly: true, sameSite: "lax", secure: req.nextUrl.protocol === "https:", path: "/", maxAge: 60 * 60 });
    } else {
        res.cookies.delete(PREVIEW_COOKIE);
    }
    return res;
}
