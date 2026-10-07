import { dirtyKeys, mergeDraft } from "./draftMerge";

type D = { caption: string; hashtags: string; cta: string };
const base: D = { caption: "", hashtags: "", cta: "" };

describe("mergeDraft: o que a pessoa escreve nunca se perde", () => {
    it("um envio de ficheiro (dados novos do servidor) não apaga o texto por gravar", () => {
        const draft = { ...base, caption: "Legenda escrita", hashtags: "#porto" };
        const fromServerAfterUpload = { ...base }; // o servidor ainda não tem o texto
        expect(mergeDraft(draft, base, fromServerAfterUpload)).toEqual(draft);
    });

    it("campos não alterados recebem o valor novo do servidor", () => {
        const draft = { ...base, caption: "Escrita" };
        const server = { caption: "", hashtags: "#outra", cta: "Reserve" };
        expect(mergeDraft(draft, base, server)).toEqual({ caption: "Escrita", hashtags: "#outra", cta: "Reserve" });
    });

    it("depois de gravar, aceita o valor normalizado pelo servidor, mas não o que se escreveu entretanto", () => {
        const sent = { caption: "Legenda ", hashtags: "porto" };
        const draft = { caption: "Legenda ", hashtags: "porto lisboa", cta: "" }; // escreveu mais nas hashtags durante a gravação
        const server = { caption: "Legenda", hashtags: "#porto", cta: "" };
        expect(mergeDraft(draft, base, server, sent)).toEqual({ caption: "Legenda", hashtags: "porto lisboa", cta: "" });
    });

    it("compara valores compostos (redes e formatos do planeamento)", () => {
        const b = { networks: { instagram: "ig_carousel" }, title: "A" };
        const d = { networks: { instagram: "ig_carousel", facebook: "fb_photos" }, title: "A" };
        const s = { networks: { instagram: "ig_carousel" }, title: "B" };
        expect(mergeDraft(d, b, s)).toEqual({ networks: { instagram: "ig_carousel", facebook: "fb_photos" }, title: "B" });
    });
});

describe("dirtyKeys", () => {
    it("lista só os campos alterados", () => {
        expect(dirtyKeys({ ...base, cta: "x" }, base)).toEqual(["cta"]);
        expect(dirtyKeys(base, base)).toEqual([]);
    });
});
