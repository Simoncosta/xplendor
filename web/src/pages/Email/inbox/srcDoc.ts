/**
 * E-mail 4 · O documento do iframe onde se mostra o HTML de uma mensagem (já limpo no servidor). O
 * iframe é isolado (sandbox sem scripts: nunca allow-scripts) e o documento traz uma CSP rígida: só
 * estilos inline e imagens data:; as imagens externas (data-xp-src) só com "Mostrar imagens".
 */
export function buildSrcDoc(html: string, showImages: boolean, dark = false): string {
    // O HTML das mensagens pressupõe fundo branco (texto escuro sem fundo próprio): fica sempre claro;
    // no tema escuro, num cartão branco com margem (como o Gmail). O esquema de cores declarado evita
    // que o navegador pinte o iframe por cima.
    const body = showImages ? html.replace(/\sdata-xp-src=/g, " src=") : html;
    const csp = `default-src 'none'; style-src 'unsafe-inline'; img-src data:${showImages ? " https: http:" : ""}; font-src data:`;
    const padding = dark ? "12px 16px" : "0";
    return `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="${csp}">`
        + `<meta name="referrer" content="no-referrer"><meta name="color-scheme" content="light"><base target="_blank">`
        + `<style>:root{color-scheme:light}body{margin:0;padding:${padding};font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;font-size:14px;line-height:1.5;color:#212529;background:#ffffff;word-wrap:break-word}img{max-width:100%;height:auto}table{max-width:100%}a{color:#3762ea}</style>`
        + `</head><body>${body}</body></html>`;
}
