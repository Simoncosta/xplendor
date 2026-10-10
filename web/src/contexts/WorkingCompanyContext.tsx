import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { getWorkingCompanies } from "helpers/laravel_helper";
import {
    WORKING_COMPANY_EVENT, getHomeCompanyId, getWorkingCompany, getWorkingCompanyId, setWorkingCompany,
} from "helpers/workingCompany";
import { isPlatformRoot } from "helpers/roles";

/**
 * Contexto de trabalho (gestão por agências): a empresa em que a pessoa trabalha neste
 * separador. A agência (e o root) escolhe o cliente no selo do cabeçalho; todas as páginas
 * leem a empresa daqui (useWorkingCompanyId), e a área de trabalho é remontada ao trocar,
 * para nada ficar com a empresa anterior. O backend volta a confirmar cada pedido.
 */

export type CompanyOption = {
    id: number;
    name: string;
    logo_path: string | null;
    /** Gerida pela agência da pessoa (para o root: pela agência dele). */
    managed: boolean;
    home: boolean;
    /** A empresa é uma agência. */
    is_agency: boolean;
};

interface WorkingCompanyState {
    workingId: number;
    workingName: string;
    homeId: number;
    /** A trabalhar num cliente (fora da própria empresa). */
    away: boolean;
    isRoot: boolean;
    options: CompanyOption[];
    /** Há mais do que uma empresa onde trabalhar (agência ou root). */
    canSwitch: boolean;
    /** A própria empresa é uma agência (vista da agência na Linha Editorial e o Painel da agência). */
    homeIsAgency: boolean;
    /** A trabalhar numa agência (a própria, ou o root numa agência): as vistas mostram todos os clientes dela. */
    agencyMode: boolean;
    switchTo: (c: { id: number; name: string }) => void;
    exit: () => void;
}

const WorkingCompanyContext = createContext<WorkingCompanyState | null>(null);

const readAuth = (): any => {
    try { const r = sessionStorage.getItem("authUser"); return r ? JSON.parse(r) : null; } catch { return null; }
};

const optionName = (c: any): string => String(c?.trade_name || c?.fiscal_name || `Empresa #${c?.id}`);

export const WorkingCompanyProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
    const auth = readAuth();
    const isRoot = isPlatformRoot(auth);
    const impersonating = !!auth?.impersonating;
    const [workingId, setWorkingId] = useState<number>(() => getWorkingCompanyId());
    const [stored, setStored] = useState(() => getWorkingCompany());
    const [options, setOptions] = useState<CompanyOption[]>([]);

    useEffect(() => {
        const sync = () => { setWorkingId(getWorkingCompanyId()); setStored(getWorkingCompany()); };
        window.addEventListener(WORKING_COMPANY_EVENT, sync);
        return () => window.removeEventListener(WORKING_COMPANY_EVENT, sync);
    }, []);

    // Em impersonation trabalha-se sempre na empresa do utilizador-alvo.
    useEffect(() => { if (impersonating && getWorkingCompany()) setWorkingCompany(null); }, [impersonating]);

    useEffect(() => {
        if (impersonating || !auth) return;
        let alive = true;
        const homeId = getHomeCompanyId();
        getWorkingCompanies().then((r: any) => {
            if (!alive) return;
            const list: any[] = Array.isArray(r?.data) ? r.data : (r?.data?.data ?? []);
            const opts: CompanyOption[] = list.map((c) => ({
                id: Number(c.id), name: optionName(c), logo_path: c.logo_path ?? null, home: Number(c.id) === homeId,
                managed: Number(c.active_management?.agency_company_id ?? 0) === homeId,
                is_agency: !!c.agency_enabled_at,
            }));
            opts.sort((a, b) => Number(b.home) - Number(a.home) || Number(b.managed) - Number(a.managed) || a.name.localeCompare(b.name, "pt"));
            setOptions(opts);
            // A relação terminou (ou o acesso mudou): volta à própria empresa.
            const current = getWorkingCompany();
            if (current && !opts.some((o) => o.id === current.id)) setWorkingCompany(null);
        }).catch(() => { /* sem lista: fica na empresa atual */ });
        return () => { alive = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [impersonating]);

    const switchTo = useCallback((c: { id: number; name: string }) => setWorkingCompany(c), []);
    const exit = useCallback(() => setWorkingCompany(null), []);

    const value = useMemo<WorkingCompanyState>(() => {
        const homeId = getHomeCompanyId();
        const home = options.find((o) => o.home);
        const working = options.find((o) => o.id === workingId);
        const homeIsAgency = !!home?.is_agency;
        return {
            workingId, homeId, isRoot, options, switchTo, exit,
            away: workingId !== homeId,
            homeIsAgency,
            agencyMode: !impersonating && !!working?.is_agency && (workingId === homeId || isRoot),
            workingName: stored?.name || options.find((o) => o.id === workingId)?.name || home?.name || "",
            canSwitch: !impersonating && (isRoot || options.length > 1),
        };
    }, [workingId, stored, options, isRoot, impersonating, switchTo, exit]);

    return <WorkingCompanyContext.Provider value={value}>{children}</WorkingCompanyContext.Provider>;
};

/** O contexto completo (selo, seletor). */
export const useWorkingCompany = (): WorkingCompanyState | null => useContext(WorkingCompanyContext);

/** A empresa em que a pessoa está a trabalhar: use SEMPRE isto em vez de ler a empresa do authUser. */
export const useWorkingCompanyId = (): number => {
    const ctx = useContext(WorkingCompanyContext);
    return ctx ? ctx.workingId : getWorkingCompanyId();
};
