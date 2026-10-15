import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { getAuditCounts } from "helpers/laravel_helper";
import { AuditCounts } from "common/models/audit.model";
import { SEVERITY } from "./auditFormat";

/**
 * XPLENDOR — a coluna "Auditoria" das listas: o nº de achados abertos da linha, com a cor da
 * urgência mais alta; ao clicar, abre os achados dessa entidade na página central.
 */
export function useAuditCounts(companyId: number | null, entityType: string, ids: string[] | null, refreshKey = 0): AuditCounts {
    const [counts, setCounts] = useState<AuditCounts>({});
    const key = ids ? ids.join(",") : "*";
    useEffect(() => {
        if (!companyId || (ids && ids.length === 0)) { setCounts({}); return; }
        let alive = true;
        getAuditCounts(companyId, entityType, ids ?? undefined)
            .then((res: any) => { if (alive) setCounts(res?.data?.counts ?? {}); })
            .catch(() => { if (alive) setCounts({}); });
        return () => { alive = false; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [companyId, entityType, key, refreshKey]);
    return counts;
}

export default function AuditCountCell({ counts, entityType, entityId, label }: { counts: AuditCounts; entityType: string; entityId: string; label?: string | null }) {
    const navigate = useNavigate();
    const c = counts[entityId];
    if (!c) return <span className="text-muted">—</span>;
    const s = SEVERITY[c.severity] ?? SEVERITY.info;
    const open = (e: React.MouseEvent) => {
        e.stopPropagation();
        const q = new URLSearchParams({ entity_type: entityType, entity_id: entityId });
        if (label) q.set("entity_label", label);
        navigate(`/auditoria?${q.toString()}`);
    };
    return (
        <button type="button" className={`badge border-0 ${s.cls}`} onClick={open} title={`${c.open} ${c.open === 1 ? "achado por resolver" : "achados por resolver"} (o mais urgente: ${s.label}). Abrir na Auditoria.`}
            aria-label={`${c.open} achados por resolver na auditoria, o mais urgente: ${s.label}`} data-testid="audit-count">
            <i className={`${s.icon} me-1`} aria-hidden />{c.open}
        </button>
    );
}
