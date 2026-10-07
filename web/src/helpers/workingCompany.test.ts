import fs from "fs";
import path from "path";
import { getHomeCompanyId, getWorkingCompany, getWorkingCompanyId, setWorkingCompany, WORKING_COMPANY_EVENT } from "./workingCompany";

const login = (id: number, companyId: number) => sessionStorage.setItem("authUser", JSON.stringify({ id, company_id: companyId, role: "user" }));

describe("contexto de trabalho", () => {
    beforeEach(() => sessionStorage.clear());

    it("sem escolha, trabalha-se na própria empresa", () => {
        login(7, 3);
        expect([getHomeCompanyId(), getWorkingCompanyId(), getWorkingCompany()]).toEqual([3, 3, null]);
    });

    it("escolher um cliente muda a empresa de trabalho e avisa a aplicação; sair volta à própria", () => {
        login(7, 3);
        const heard = jest.fn();
        window.addEventListener(WORKING_COMPANY_EVENT, heard);
        setWorkingCompany({ id: 12, name: "Domiway" });
        expect([getWorkingCompanyId(), getWorkingCompany()?.name]).toEqual([12, "Domiway"]);
        setWorkingCompany(null);
        expect(getWorkingCompanyId()).toBe(3);
        expect(heard).toHaveBeenCalledTimes(2);
        window.removeEventListener(WORKING_COMPANY_EVENT, heard);
    });

    it("a escolha só vale para a pessoa que a fez (outra sessão no mesmo separador volta à própria empresa)", () => {
        login(7, 3);
        setWorkingCompany({ id: 12, name: "Domiway" });
        login(8, 5);
        expect(getWorkingCompanyId()).toBe(5);
    });

    it("escolher a própria empresa é o mesmo que sair do contexto", () => {
        login(7, 3);
        setWorkingCompany({ id: 3, name: "Agência" });
        expect(getWorkingCompany()).toBeNull();
    });
});

/**
 * Arquitetura: nenhuma página lê a empresa do authUser por conta própria (seria trabalhar
 * e gravar na empresa da pessoa enquanto o selo diz outra). Só o contexto de trabalho.
 */
describe("arquitetura do contexto de trabalho", () => {
    const SRC = path.resolve(__dirname, "..");
    const ALLOWED = new Set(["helpers/workingCompany.ts", "helpers/impersonation.ts", "Components/Common/ImpersonationBanner.tsx"]);
    const PATTERNS = [
        /JSON\.parse\([^)]*\)\s*\??\.\s*company_id/,
        /\b(authUser|auth|obj|o|u|user|loggedUser|currentUser|authUserRaw)\s*\??\.\s*company_id\b/,
        /\{[^}]*\bcompany_id\b[^}]*\}\s*=\s*JSON\.parse/,
    ];
    const files = (dir: string): string[] => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) return e.name === "node_modules" ? [] : files(p);
        return /\.(ts|tsx)$/.test(e.name) && !/\.test\./.test(e.name) ? [p] : [];
    });

    it("só o contexto de trabalho lê authUser.company_id", () => {
        const offenders = files(SRC).flatMap((f) => {
            const rel = path.relative(SRC, f).split(path.sep).join("/");
            if (ALLOWED.has(rel)) return [];
            return fs.readFileSync(f, "utf8").split("\n")
                .map((line, i) => (PATTERNS.some((re) => re.test(line)) ? `${rel}:${i + 1}  ${line.trim()}` : null))
                .filter((x): x is string => x !== null);
        });
        expect(offenders).toEqual([]);
    });
});
