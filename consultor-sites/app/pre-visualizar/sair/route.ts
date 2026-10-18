import { NextResponse, type NextRequest } from "next/server";
import { PREVIEW_COOKIE } from "@/lib/api";

/** Sair da pré-visualização: apaga o cookie e volta à página inicial. */
export function GET(req: NextRequest) {
    const res = NextResponse.redirect(new URL("/", req.nextUrl), 303);
    res.cookies.delete(PREVIEW_COOKIE);
    return res;
}
