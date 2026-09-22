import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import logoLight from '../../../assets/images/logo-light.png';
import { CTA_WHATSAPP_URL } from '../../Landing/data/constants';

const NAV_LINKS = [
    { href: '#ramos', label: 'Vertentes' },
    { href: '#capacidades', label: 'Capacidades' },
    { href: '#servicos', label: 'Serviços' },
];

const XNav: React.FC = () => {
    const [scrolled, setScrolled] = useState(false);
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 40);
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    return (
        <nav className={`x-nav${scrolled ? ' scrolled' : ''}`} aria-label="Navegação principal">
            <div className="x-container">
                <div className="x-nav-row">
                    <a href="#hero" className="x-brand" aria-label="XPLENDOR — início">
                        <img src={logoLight} alt="XPLENDOR" />
                    </a>

                    <ul className="x-nav-links">
                        {NAV_LINKS.map((l) => (
                            <li key={l.href}><a href={l.href}>{l.label}</a></li>
                        ))}
                    </ul>

                    <div className="x-nav-cta">
                        <Link to="/login" className="x-nav-login">Entrar</Link>
                        <a
                            href={CTA_WHATSAPP_URL}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="x-btn x-btn-primary x-btn-sm"
                        >
                            Get started
                        </a>
                        <button
                            type="button"
                            className="x-menu-toggle"
                            onClick={() => setOpen((v) => !v)}
                            aria-label={open ? 'Fechar menu' : 'Abrir menu'}
                            aria-expanded={open}
                        >
                            <i className={open ? 'ri-close-line' : 'ri-menu-line'} aria-hidden="true" />
                        </button>
                    </div>
                </div>

                <div className={`x-mobile-menu${open ? ' open' : ''}`}>
                    {NAV_LINKS.map((l) => (
                        <a key={l.href} href={l.href} onClick={() => setOpen(false)}>{l.label}</a>
                    ))}
                    <Link to="/login" onClick={() => setOpen(false)}>Entrar na plataforma</Link>
                    <a
                        href={CTA_WHATSAPP_URL}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="x-btn x-btn-primary"
                        onClick={() => setOpen(false)}
                    >
                        Get started
                    </a>
                </div>
            </div>
        </nav>
    );
};

export default XNav;
