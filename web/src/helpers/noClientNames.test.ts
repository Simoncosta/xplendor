import fs from "fs";
import path from "path";

// Os nomes dos clientes não se escrevem no código da web: as lojas e os armazéns vêm da base de dados.
// Ficam de fora os testes e as fixtures.
const PATTERN = /yuko|tabern|costa\s+cabral/i;
const SRC = path.resolve(__dirname, "..");
const IGNORED = (file: string) => /\.test\.[jt]sx?$/.test(file) || /[\\/](__tests__|fixtures|__fixtures__)[\\/]/.test(file);

const sourceFiles = (dir: string): string[] =>
    fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return sourceFiles(full);
        return /\.(tsx?|jsx?|json|scss|css|html)$/.test(entry.name) && !IGNORED(full) ? [full] : [];
    });

describe("nomes de clientes no código", () => {
    it("não aparece nenhum nome de cliente no código da web", () => {
        const found = sourceFiles(SRC).flatMap((file) =>
            fs.readFileSync(file, "utf8").split("\n")
                .map((line, i) => (PATTERN.test(line) ? `${path.relative(SRC, file)}:${i + 1}: ${line.trim()}` : null))
                .filter((x): x is string => x !== null)
        );
        expect(found).toEqual([]);
    });
});
