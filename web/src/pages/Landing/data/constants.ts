export const CTA_WHATSAPP_URL =
    'https://wa.me/351938963526?text=Ol%C3%A1%2C%20gostaria%20de%20saber%20mais%20sobre%20a%20XPLENDOR%20para%20o%20meu%20stand.';

const WHATSAPP_NUMBER = '351938963526';

/** CTA genérico da landing de autocaravanas (/autocaravanas). */
export const CTA_WHATSAPP_URL_MOTORHOMES = `https://wa.me/${WHATSAPP_NUMBER}?text=${encodeURIComponent(
    'Olá, gostaria de saber mais sobre a XPLENDOR para o meu stand de autocaravanas.'
)}`;

/** URL de WhatsApp com mensagem pré-preenchida específica de um plano.
 *  Usado só nos cards de pacote — os CTAs genéricos mantêm CTA_WHATSAPP_URL. */
export const buildWhatsAppUrl = (planName: string): string => {
    const message = `Olá, interessa-me o plano ${planName} para o meu stand.`;
    return `https://wa.me/${WHATSAPP_NUMBER}?text=${encodeURIComponent(message)}`;
};
