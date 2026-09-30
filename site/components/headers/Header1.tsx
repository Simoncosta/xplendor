"use client";
import Link from "next/link";
import Image from "next/image";
import { useEffect, useState } from "react";
import AnimatedButton from "../animation/AnimatedButton";
import ThemeSwitcherButton from "./ColorSwitcher";

export default function Header1() {
  const [isHidden, setIsHidden] = useState(false);
  useEffect(() => {
    const handleScroll = () => {
      const currentScrollPos = window.pageYOffset;
      setIsHidden(currentScrollPos > 10);
    };

    window.addEventListener("scroll", handleScroll);
    return () => window.removeEventListener("scroll", handleScroll);
  }, []);

  return (
    <header id="header" className={`mxd-header ${isHidden ? "is-hidden" : ""}`}>
      {/* header logo */}
      <div className="mxd-header__logo loading__fade">
        <Link href={`/`} className="mxd-logo">
          {/* logo icon (X da XPLENDOR): versao clara/escura por tema */}
          <Image
            className="mxd-logo__image xplendor-x xplendor-x--dark"
            src="/img/logo/xplendor-x-dark.png"
            alt="XPLENDOR"
            width={458}
            height={461}
            priority
          />
          <Image
            className="mxd-logo__image xplendor-x xplendor-x--light"
            src="/img/logo/xplendor-x-light.png"
            alt="XPLENDOR"
            width={458}
            height={461}
            priority
          />
          {/* logo text */}
          <span className="mxd-logo__text">XPLENDOR</span>
        </Link>
      </div>
      {/* header controls */}
      <div className="mxd-header__controls loading__fade">
        <ThemeSwitcherButton />

        {/* Área de Cliente: sai do site (Next) para a app CRA em /app/login.
            target="_self" força uma âncora <a> real (navegação de browser completa),
            em vez do Link do Next, que tentaria uma rota interna inexistente. */}
        <AnimatedButton
          text="Área de Cliente"
          className="btn btn-anim btn-default btn-mobile-icon btn-outline slide-right"
          href="/app/login"
          target="_self"
        >
          <i className="ph-bold ph-arrow-up-right" />
        </AnimatedButton>
      </div>
    </header>
  );
}
