import { promoKey, promoTag, splitPreview } from "./PromoModal";

/** A repartição mostrada no modal segue a regra do servidor (InvoiceDiscountAllocator). */
test("reparte pelo valor e põe o resto dos cêntimos na maior", () => {
    expect(splitPreview(101, [1000, 2000, 1000])).toEqual([25, 51, 25]);
    expect(splitPreview(1315, [7075, 1415])).toEqual([1096, 219]);
    expect(splitPreview(50, [0, 0])).toEqual([50, 0]);
});

test("o código como chave e a etiqueta da linha", () => {
    expect(promoKey(" 045238 ")).toBe("45238");
    const line: any = { promo_code: "43520", discount: 0.96 };
    expect(promoTag(line, [{ code: "43520" } as any])?.text).toMatch(/Promoção 43520 · −0,96\s€/);
    expect(promoTag(line, [])).toEqual({ text: "Promoção sem desconto na fatura", warn: true });
    expect(promoTag({ promo_code: null } as any, [])).toBeNull();
});
