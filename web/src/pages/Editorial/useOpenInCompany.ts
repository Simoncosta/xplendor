import { useCallback } from "react";
import { useNavigate } from "react-router-dom";
import { useWorkingCompany } from "contexts/WorkingCompanyContext";

/**
 * Abrir uma página de OUTRA empresa a partir da Linha Editorial da agência (ex.: o Perfil da
 * Marca do cliente escolhido no filtro): passa a trabalhar nessa empresa e só depois navega,
 * para a página não abrir com os dados da agência.
 */
export default function useOpenInCompany() {
    const navigate = useNavigate();
    const wc = useWorkingCompany();
    return useCallback((companyId: number, path: string) => {
        if (wc && companyId && companyId !== wc.workingId) {
            const name = wc.options.find((o) => o.id === companyId)?.name ?? "";
            wc.switchTo({ id: companyId, name });
        }
        navigate(path);
    }, [navigate, wc]);
}
