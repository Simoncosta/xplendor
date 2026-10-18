/** O link do WhatsApp com a mensagem preenchida (o número só com os algarismos). */
export function whatsappHref(number: string, text: string): string {
    return `https://wa.me/${number.replace(/[^0-9]/g, "")}?text=${encodeURIComponent(text)}`;
}

/** A mensagem do perfil com {nome} (o primeiro nome do consultor), {referencia} e {empreendimento}. */
export function fillMessage(template: string, values: { nome: string; referencia?: string; empreendimento?: string }): string {
    return template.replaceAll("{nome}", values.nome).replaceAll("{referencia}", values.referencia ?? "").replaceAll("{empreendimento}", values.empreendimento ?? "");
}
