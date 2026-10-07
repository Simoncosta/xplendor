import { useWorkingCompany, useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import XplendorChargesCard from "./XplendorChargesCard";

/**
 * As faturas da XPLENDOR por resolver, na empresa em que se trabalha. Só para os
 * utilizadores da própria empresa e o root: a agência gestora nunca as vê (nem pede).
 */
export default function XplendorChargesNotice({ onChanged }: { onChanged?: () => void }) {
    const ctx = useWorkingCompany();
    const companyId = useWorkingCompanyId();
    if (ctx?.away && !ctx.isRoot) return null;

    return <XplendorChargesCard companyId={companyId} onChanged={onChanged} />;
}
