import { NextResponse } from "next/server";

/** O healthcheck do contentor (não fala com a XPLENDOR). */
export function GET() {
    return NextResponse.json({ ok: true });
}
