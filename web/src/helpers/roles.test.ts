import fs from "fs";
import path from "path";
import { isAdminRole, isPlatformRoot, isRootRole } from "./roles";

/**
 * ACL (F4): o ecrã nunca decide uma permissão pelo papel. As permissões vêm do backend
 * (/my-access, useCan); o papel só se lê em helpers/roles.ts.
 */
const SRC = path.resolve(__dirname, "..");
const ROLE_COMPARISON = /role\s*(===|!==|==|!=)\s*["'`]|["'`](root|admin|user)["'`]\s*(===|!==|==|!=)\s*[\w.?]*role\b/;

const files = (dir: string): string[] =>
    fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) return e.name === "node_modules" ? [] : files(p);
        return /\.(ts|tsx)$/.test(e.name) && !/\.test\.(ts|tsx)$/.test(e.name) ? [p] : [];
    });

describe("papéis no ecrã", () => {
    it("nenhum ficheiro compara o papel, exceto helpers/roles.ts", () => {
        const offenders = files(SRC)
            .filter((f) => !f.endsWith(path.join("helpers", "roles.ts")))
            .flatMap((f) => fs.readFileSync(f, "utf8").split("\n").map((line, i) => ({ f, line, i })))
            .filter(({ line }) => ROLE_COMPARISON.test(line))
            .map(({ f, line, i }) => `${path.relative(SRC, f)}:${i + 1} ${line.trim()}`);
        expect(offenders).toEqual([]);
    });

    it("o root de verdade não está em sessão como cliente", () => {
        expect(isPlatformRoot({ role: "root" })).toBe(true);
        expect(isPlatformRoot({ role: "root", impersonating: true })).toBe(false);
        expect(isRootRole("admin")).toBe(false);
        expect(isAdminRole("admin")).toBe(true);
    });
});
