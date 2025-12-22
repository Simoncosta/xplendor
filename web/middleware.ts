import { NextResponse } from 'next/server';
import type { NextRequest } from 'next/server';

export function middleware(req: NextRequest) {
    const token = req.cookies.get('token')?.value || null;
    const locked = req.cookies.get('locked')?.value === 'true';
    const path = req.nextUrl.pathname;

    const isAuthPage = path.startsWith('/auth');
    const isLockPage = path.startsWith('/auth/lockscreen');

    // FIRST PRIORITY — SE ESTIVER BLOQUEADO → só pode ver /auth/lockscreen
    if (locked && !isLockPage) {
        return NextResponse.redirect(new URL('/auth/lockscreen', req.url));
    }

    // NOT LOGGED IN → só pode ver /auth/*
    if (!token && !isAuthPage) {
        return NextResponse.redirect(new URL('/auth/login', req.url));
    }

    // LOGADO → não permitir acessar login/register
    if (token && isAuthPage && !isLockPage) {
        return NextResponse.redirect(new URL('/', req.url));
    }

    return NextResponse.next();
}

export const config = {
    matcher: [
        // protege tudo exceto:
        // - _next (builds)
        // - arquivos estáticos
        // - API routes (importantíssimo)
        '/((?!_next|api|favicon.ico|assets|images|img|icons|fonts|public).*)',
    ],
};