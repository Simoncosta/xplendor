import { createHmac, timingSafeEqual } from "node:crypto";

/** A assinatura da revalidação: HMAC-SHA256 de "carimbo.corpo"; recusa acima de 5 minutos de diferença. */
export function verifySignature(secret: string, timestamp: string, body: string, signature: string, nowSeconds: number): boolean {
    if (!secret || !/^\d+$/.test(timestamp) || Math.abs(nowSeconds - Number(timestamp)) > 300) return false;
    const expected = Buffer.from(createHmac("sha256", secret).update(`${timestamp}.${body}`).digest("hex"));
    const given = Buffer.from(signature);
    return expected.length === given.length && timingSafeEqual(expected, given);
}
