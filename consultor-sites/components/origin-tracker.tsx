"use client";

import { useEffect } from "react";
import { captureOrigin } from "@/lib/origin";

/** Guarda a origem da visita na sessão do separador (ver lib/origin.ts). Não desenha nada. */
export function OriginTracker() {
    useEffect(() => { captureOrigin(); }, []);
    return null;
}
