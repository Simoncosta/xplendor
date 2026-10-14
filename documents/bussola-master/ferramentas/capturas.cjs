// Bússola Master, R4: as capturas do ecrã (claro e escuro, computador e telemóvel), só com dados fictícios.
// Uso: NODE_PATH=~/.npm/_npx/e41f203b7505f1fb/node_modules node capturas.cjs sessoes.json documents/bussola-master/capturas
// sessoes.json: a linha ===SESSOES=== do demo-criar.php (os tokens só valem até à limpeza).
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://localhost:3000/app';
const EXE = process.env.PW_EXE || `${process.env.HOME}/Library/Caches/ms-playwright/chromium_headless_shell-1223/chrome-headless-shell-mac-arm64/chrome-headless-shell`;
const [sessoesFile, out] = process.argv.slice(2);
const S = JSON.parse(fs.readFileSync(sessoesFile, 'utf8'));
const POST_IMOVEL = Number(process.env.POST_IMOVEL || 0);
const POST_RADAR = Number(process.env.POST_RADAR || 0);
fs.mkdirSync(out, { recursive: true });

const abrir = async (browser, authUser, { tema = 'light', telemovel = false } = {}) => {
    const ctx = await browser.newContext(telemovel
        ? { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
        : { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    await ctx.addInitScript(({ authUser, tema }) => {
        sessionStorage.setItem('authUser', JSON.stringify(authUser));
        sessionStorage.removeItem('xp-working-company');
        localStorage.setItem('xp-theme', tema);
        localStorage.setItem('xplendor_cookie_consent', 'denied'); // o aviso de cookies não tapa as capturas
        // A janela "Como funciona" da Linha Editorial abre só na primeira visita de cada pessoa: dá-se por vista.
        localStorage.setItem(`xp-editorial-howto:${authUser.id}`, '1');
    }, { authUser, tema });
    return ctx;
};
const ir = async (page, path, testId) => {
    await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' });
    if (testId) await page.getByTestId(testId).first().waitFor({ timeout: 20000 });
    await page.waitForTimeout(600);
};
const corte = async (page, testId, file) => {
    // A barra de topo é fixa e tapava o início dos cartões: escondida só nos cortes.
    await page.addStyleTag({ content: '#page-topbar{visibility:hidden !important}' });
    const el = page.getByTestId(testId).first();
    await el.scrollIntoViewIfNeeded();
    await el.screenshot({ path: `${out}/${file}.png` });
};
const inteira = async (page, file) => {
    await page.addStyleTag({ content: '#page-topbar{visibility:visible !important}' });
    await page.screenshot({ path: `${out}/${file}.png`, fullPage: true });
};

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: EXE });
    for (const [t, tema] of [['claro', 'light'], ['escuro', 'dark']]) {
        // Consultor imobiliário (administrador): a Bússola do imobiliário com o Radar.
        let ctx = await abrir(browser, S.admin_imob, { tema }); let page = await ctx.newPage();
        await ir(page, '/marketing/bussola', 'imoveis-bussola');
        await inteira(page, `01-imobiliario-pagina-completa-${t}`);
        await corte(page, 'bussola-plays', `02-imobiliario-jogadas-${t}`);
        await corte(page, 'radar-card', `03-imobiliario-radar-${t}`);
        await corte(page, 'imoveis-price', `04-imobiliario-preco-ine-${t}`);
        // A carteira e a ficha de um imóvel.
        await ir(page, '/imoveis', 'imoveis-page');
        await inteira(page, `05-imoveis-carteira-${t}`);
        await page.getByRole('button', { name: /Editar DEMO-001/ }).click();
        await page.getByTestId('property-modal').waitFor(); await page.waitForTimeout(500);
        await page.locator('.modal-content').first().screenshot({ path: `${out}/06-imoveis-ficha-${t}.png` });
        await page.keyboard.press('Escape');
        // A publicação ligada a um imóvel sem classe energética: o aviso no Conteúdo.
        if (POST_IMOVEL) {
            await ir(page, `/editorial?publicacao=${POST_IMOVEL}`);
            await page.getByRole('link', { name: /Conteúdo/ }).first().click();
            await page.getByTestId('post-compliance').waitFor({ timeout: 20000 }); await page.waitForTimeout(400);
            await corte(page, 'post-compliance', `07-publicacao-aviso-classe-energetica-${t}`);
        }
        // O painel "Agora" da Linha Editorial.
        await ir(page, '/editorial', 'editorial-now');
        await page.getByTestId('editorial-now').click();
        await page.getByTestId('radar-signal').first().waitFor({ timeout: 20000 }); await page.waitForTimeout(500);
        await page.locator('.offcanvas.show').last().screenshot({ path: `${out}/08-linha-editorial-agora-${t}.png` });
        await ctx.close();

        // Stand de demonstração: o Radar a seguir às jogadas e a ideia com o gancho e as fontes.
        ctx = await abrir(browser, S.admin_stand, { tema }); page = await ctx.newPage();
        await ir(page, '/marketing/bussola', 'stand-bussola');
        await corte(page, 'radar-card', `09-stand-radar-${t}`);
        if (POST_RADAR) {
            await ir(page, `/editorial?publicacao=${POST_RADAR}`, 'post-hook');
            await corte(page, 'post-hook', `10-publicacao-gancho-e-fontes-${t}`);
        }
        await ctx.close();

        // Restaurante de demonstração: a Bússola da restauração desligada e o Radar por baixo.
        ctx = await abrir(browser, S.admin_tasca, { tema }); page = await ctx.newPage();
        await ir(page, '/marketing/bussola', 'radar-card');
        await inteira(page, `11-restauracao-desligada-e-radar-${t}`);
        await ctx.close();

        // Agência convidada no consultor de demonstração: as jogadas e o Radar.
        ctx = await abrir(browser, S.agencia_imob, { tema }); page = await ctx.newPage();
        await ir(page, '/marketing/bussola', 'imoveis-bussola');
        await corte(page, 'bussola-plays', `12-agencia-convidada-jogadas-${t}`);
        await corte(page, 'radar-card', `13-agencia-convidada-radar-${t}`);
        await ctx.close();

        // Root na XPLENDOR de dev: só cortes (o menu lateral e o seletor mostram empresas reais).
        ctx = await abrir(browser, S.root, { tema }); page = await ctx.newPage();
        await ir(page, '/marketing/bussola', 'radar-card');
        await corte(page, 'radar-card', `14-root-xplendor-radar-${t}`);
        await ir(page, '/admin/radar', 'radar-r2');
        await corte(page, 'radar-r2', `15-root-admin-relevancia-${t}`);
        await page.getByTestId('radar-r2').getByRole('tab', { name: 'Freguesias e INE' }).click(); await page.waitForTimeout(500);
        await corte(page, 'radar-r2', `16-root-admin-freguesias-ine-${t}`);
        await ctx.close();

        // Telemóvel: a Bússola do imobiliário e a carteira.
        ctx = await abrir(browser, S.admin_imob, { tema, telemovel: true }); page = await ctx.newPage();
        await ir(page, '/marketing/bussola', 'imoveis-bussola');
        await inteira(page, `17-telemovel-imobiliario-${t}`);
        await ir(page, '/imoveis', 'imoveis-page');
        await inteira(page, `18-telemovel-imoveis-${t}`);
        const scroll = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        if (scroll > 1) console.log(`AVISO: scroll horizontal de ${scroll}px em /imoveis no telemóvel (${t})`);
        await ctx.close();
    }
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
