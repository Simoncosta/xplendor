import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Container } from 'reactstrap';
import CTAButton from './CTAButton';
import { CTA_WHATSAPP_URL } from '../data/constants';

interface NavLink {
    href: string;
    label: string;
}

const NAV_LINKS: NavLink[] = [
    { href: '#problema', label: 'O problema' },
    { href: '#como-funciona', label: 'Como funciona' },
    { href: '#pacotes', label: 'Pacotes' },
    { href: '#faq', label: 'FAQ' },
];

const LandingNav: React.FC = () => {
    const [scrolled, setScrolled] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 50);
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    return (
        <nav
            className={`lp-navbar${scrolled ? ' scrolled' : ''}`}
            aria-label="Navegação principal"
        >
            <Container>
                <div className="d-flex align-items-center justify-content-between">
                    <a
                        href="#hero"
                        className="lp-navbar-brand"
                        aria-label="XPLENDOR — início da página"
                    >
                        XPLENDOR
                    </a>

                    <ul className="lp-nav-links mb-0" aria-label="Secções da página">
                        {NAV_LINKS.map((link) => (
                            <li key={link.href}>
                                <a href={link.href}>{link.label}</a>
                            </li>
                        ))}
                    </ul>

                    <div className="d-flex align-items-center gap-3">
                        <Link to="/login" className="lp-nav-login">
                            Entrar
                        </Link>

                        <CTAButton
                            href={CTA_WHATSAPP_URL}
                            size="sm"
                            className="lp-nav-cta"
                        >
                            <i className="ri-whatsapp-line" aria-hidden="true" />
                            Falar connosco
                        </CTAButton>

                        <button
                            type="button"
                            className="lp-menu-toggle"
                            onClick={() => setMenuOpen((v) => !v)}
                            aria-label={menuOpen ? 'Fechar menu' : 'Abrir menu'}
                            aria-expanded={menuOpen}
                        >
                            <i
                                className={menuOpen ? 'ri-close-line' : 'ri-menu-line'}
                                aria-hidden="true"
                            />
                        </button>
                    </div>
                </div>

                {menuOpen && (
                    <div className="lp-mobile-menu" role="navigation" aria-label="Menu móvel">
                        <ul className="list-unstyled mb-3">
                            {NAV_LINKS.map((link) => (
                                <li key={link.href}>
                                    <a
                                        href={link.href}
                                        onClick={() => setMenuOpen(false)}
                                    >
                                        {link.label}
                                    </a>
                                </li>
                            ))}
                            <li>
                                <Link
                                    to="/login"
                                    className="lp-mobile-login"
                                    onClick={() => setMenuOpen(false)}
                                >
                                    Entrar na plataforma
                                </Link>
                            </li>
                        </ul>
                        <CTAButton
                            href={CTA_WHATSAPP_URL}
                            size="sm"
                            className="w-100 justify-content-center"
                        >
                            <i className="ri-whatsapp-line" aria-hidden="true" />
                            Falar connosco
                        </CTAButton>
                    </div>
                )}
            </Container>
        </nav>
    );
};

export default LandingNav;
