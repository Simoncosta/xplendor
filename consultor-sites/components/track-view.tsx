"use client";

import { useEffect } from "react";
import { track } from "@/lib/track";

/** Conta uma visualização da página do imóvel ou do empreendimento (uma por carregamento). */
export function TrackView({ subject, slug }: { subject: "property" | "development"; slug: string }) {
    useEffect(() => { track("view", subject, slug); }, [subject, slug]);
    return null;
}
