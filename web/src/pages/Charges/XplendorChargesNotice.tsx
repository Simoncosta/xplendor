import { useWorkingCompany, useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { useModules } from "contexts/ModulesContext";
import XplendorChargesCard from "./XplendorChargesCard";

/**
 * As faturas da XPLENDOR por resolver, na empresa em que se trabalha. Só para os
 * utilizadores da própria empresa e o root: a agência gestora nunca as vê (nem pede).
 * A faturação da XPLENDOR é só do Administrador (faturacao_xplendor.ver).
 */
export default function XplendorChargesNotice({ onChanged }: { onChanged?: () => void }) {
    const ctx = useWorkingCompany();
    const companyId = useWorkingCompanyId();
    const { can } = useModules();
    if ((ctx?.away && !ctx.isRoot) || !can("faturacao_xplendor.ver")) return null;

    return <XplendorChargesCard companyId={companyId} onChanged={onChanged} />;
}
