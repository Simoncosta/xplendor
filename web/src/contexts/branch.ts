/**
 * XPLENDOR — o menu por ramo. Um item com ramo (por exemplo, os cadastros da restauração) aparece:
 * sempre ao root; à empresa desse ramo; e à empresa sem ramo conhecido (sem setor, ou com um setor
 * que não é de nenhum ramo), onde decide só o módulo do item. Esconde-se apenas às empresas com
 * outro ramo explícito (por exemplo, Carros).
 */
export function branchAllows(itemBranch: string | undefined, companyBranch: string | null, isRoot: boolean): boolean {
    return !itemBranch || isRoot || companyBranch === null || companyBranch === itemBranch;
}
