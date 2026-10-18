"use client";

import * as Dialog from "@radix-ui/react-dialog";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";
import { NAV } from "@/content/textos";
import { cn } from "@/lib/cn";
import { buttonVariants } from "@/components/ui/button";
import { Signature } from "@/components/signature";

export type NavItem = { href: string; label: string };

/**
 * O cabeçalho: a assinatura (ou o nome), o menu e o botão "Estudo da sua zona". Na página
 * inicial assenta sobre o topo escuro e fica sólido (creme) ao descer; nas outras é sempre sólido.
 * No telemóvel, o menu abre num painel azul escuro a toda a altura.
 */
export function Header({ name, signatureUrl, items, cta, banner }: { name: string; signatureUrl: string | null; items: NavItem[]; cta: NavItem; banner?: React.ReactNode }) {
    const pathname = usePathname();
    const overHero = pathname === "/";
    const [scrolled, setScrolled] = useState(false);
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 48);
        onScroll();
        window.addEventListener("scroll", onScroll, { passive: true });
        return () => window.removeEventListener("scroll", onScroll);
    }, []);

    const dark = overHero && !scrolled;
    const brand = signatureUrl
        ? <Signature alt={name} className="h-9 md:h-10" />
        : <span className="font-display text-[1.4rem] leading-none tracking-[-0.01em]">{name}</span>;

    return (
        <header className={cn("inset-x-0 top-0 z-40 transition-colors duration-300", overHero ? "fixed" : "sticky",
            dark ? "bg-transparent text-cream" : "bg-cream text-navy border-b border-line")}>
            {banner}
            <div className="container-site flex h-[4.5rem] items-center justify-between gap-6">
                <Link href="/" className="shrink-0" aria-label={`${name}: ${NAV.inicio}`}>{brand}</Link>
                <nav aria-label="Principal" className="hidden lg:block">
                    <ul className="flex items-center gap-7 text-[0.95rem] font-medium">
                        {items.map((i) => (
                            <li key={i.href}>
                                <Link href={i.href} aria-current={pathname === i.href ? "page" : undefined}
                                    className={cn("py-2 transition-opacity hover:opacity-70", (pathname === i.href || pathname.startsWith(`${i.href}/`)) && "underline decoration-amber decoration-2 underline-offset-[0.45em]")}>
                                    {i.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
                <div className="flex items-center gap-3">
                    <Link href={cta.href} className={cn(buttonVariants({ variant: dark ? "outlineDark" : "outline", size: "sm" }), "hidden sm:inline-flex")}>{cta.label}</Link>
                    <Dialog.Root open={open} onOpenChange={setOpen}>
                        <Dialog.Trigger className="lg:hidden -mr-2 inline-flex h-11 w-11 items-center justify-center" aria-label={NAV.menu}>
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 7h18M3 12h18M9 17h12" stroke="currentColor" strokeWidth="1.6" /></svg>
                        </Dialog.Trigger>
                        <Dialog.Portal>
                            <Dialog.Content className="fixed inset-0 z-50 flex flex-col bg-navy text-cream outline-none" aria-describedby={undefined}>
                                <Dialog.Title className="sr-only">{NAV.menu}</Dialog.Title>
                                <div className="container-site flex h-[4.5rem] items-center justify-between">
                                    <Link href="/" onClick={() => setOpen(false)} aria-label={`${name}: ${NAV.inicio}`}>
                                        {signatureUrl ? <Signature alt={name} className="h-9" /> : <span className="font-display text-[1.4rem]">{name}</span>}
                                    </Link>
                                    <Dialog.Close className="-mr-2 inline-flex h-11 w-11 items-center justify-center" aria-label={NAV.fecharMenu}>
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19" stroke="currentColor" strokeWidth="1.6" /></svg>
                                    </Dialog.Close>
                                </div>
                                <nav aria-label="Principal" className="container-site flex flex-1 flex-col justify-between pb-10 pt-8">
                                    <ul className="space-y-1">
                                        {items.map((i) => (
                                            <li key={i.href}>
                                                <Link href={i.href} onClick={() => setOpen(false)} className="block py-2 font-display text-[2.4rem] leading-tight">{i.label}</Link>
                                            </li>
                                        ))}
                                    </ul>
                                    <Link href={cta.href} onClick={() => setOpen(false)} className={cn(buttonVariants({ variant: "primary", size: "lg" }), "w-full")}>{cta.label}</Link>
                                </nav>
                            </Dialog.Content>
                        </Dialog.Portal>
                    </Dialog.Root>
                </div>
            </div>
        </header>
    );
}
