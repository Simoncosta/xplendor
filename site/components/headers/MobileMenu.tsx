"use client";
import Link from "next/link";
import menuItemsData from "@/data/menu.json"; // adjust path accordingly

// O menu pode ter itens simples (title+href) ou com submenu. Tipamos o JSON para
// que os itens sem submenu continuem válidos (a Fase 1 usa só itens simples).
type MenuSubItem = { label: string; href: string };
type MenuItem = { title: string; href?: string; submenu?: MenuSubItem[] };
const menuItems = menuItemsData as MenuItem[];

import { useEffect, useLayoutEffect, useRef, useState } from "react";

import gsap from "gsap";
import Flip from "gsap/Flip";
import { usePathname } from "next/navigation";
import AnimatedButton from "../animation/AnimatedButton";

gsap.registerPlugin(Flip);

export default function MobileMenu() {
  const pathname = usePathname();
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const [isActive, setIsActive] = useState(false);
  const [activeSubmenu, setActiveSubmenu] = useState(-1);
  const submenuRefs = useRef<(HTMLUListElement | null)[]>([]);
  // refs for the two *containers* the element will move between
  const hamburgerBtnRef = useRef<HTMLAnchorElement | null>(null); // .mxd-nav__hamburger
  const menuContainRef = useRef<HTMLDivElement | null>(null); // .mxd-menu__contain

  // the single element that flips between the two containers
  const flipBaseRef = useRef<HTMLDivElement | null>(null); // .hamburger__base

  // Store scrollHeight values
  const [submenuHeights, setSubmenuHeights] = useState<number[]>([]);
  const handleToggle = () => {
    if (isActive) {
      setIsActive(false);
      setTimeout(
        () => {
          setIsMenuOpen(false);
        },

        800
      );
    } else {
      setIsMenuOpen(true);
      setTimeout(
        () => {
          setIsActive(true);
        },

        600
      );
    }
  };
  const isMenuActive = (link?: string) =>
    link?.split("/")[1] == pathname.split("/")[1];

  useEffect(() => {
    // Get scrollHeight for each submenu and store in state
    const heights = submenuRefs.current.map((submenu) =>
      submenu ? submenu.scrollHeight : 0
    );
    setSubmenuHeights(heights);
  }, []);

  useEffect(() => {
    setActiveSubmenu(-1);
    if (isActive) {
      handleToggle();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname]);

  // === FLIP ONLY on state change ===
  useLayoutEffect(() => {
    const flipEl = flipBaseRef.current;
    const toMenu = isMenuOpen;

    if (!flipEl || !hamburgerBtnRef.current || !menuContainRef.current) return;

    // Capture current position/sizes before DOM move
    const state = Flip.getState(flipEl);

    // Move the node to its new container
    if (toMenu) {
      menuContainRef.current.appendChild(flipEl);
    } else {
      hamburgerBtnRef.current.appendChild(flipEl);
    }

    // Animate from previous to new layout
    Flip.from(state, {
      duration: 0.8,
      ease: "power4.inOut",
    });
  }, [isMenuOpen]);
  return (
    <nav
      className={`mxd-nav__wrap  ${isActive ? "active_menu" : ""} `}
      data-lenis-prevent=""
    >
      {/* Hamburger Start */}
      <div className="mxd-nav__contain loading__fade">
        <a
          href="#"
          onClick={(e) => {
            e.preventDefault();
            handleToggle();
          }}
          className={`mxd-nav__hamburger ${isMenuOpen ? "nav-open" : ""}`}
          ref={hamburgerBtnRef}
        >
          {/* flip element */}
          <div className="hamburger__base" ref={flipBaseRef} />
          <div className="hamburger__line" />
          <div className="hamburger__line" />
        </a>
      </div>
      {/* Hamburger End */}
      {/* Main Navigation Start */}
      <div className={`mxd-menu__wrapper ${isActive ? "active_menu" : ""} `}>
        {/* background active layer */}
        <div className="mxd-menu__base" />
        {/* menu container */}
        <div className="mxd-menu__contain" ref={menuContainRef}>
          <div className="mxd-menu__inner">
            {/* left side */}
            <div className="mxd-menu__left">
              <p
                className="mxd-menu__caption fade-in-elm"
                style={{ transitionDelay: "0.4s" }}
              >
                Marketing e tecnologia
                <br />
                que trabalham juntos
              </p>
              <div className="main-menu">
                <nav className="main-menu__content">
                  <ul id="main-menu" className="main-menu__accordion">
                    {menuItems.map((item, index) => (
                      <li
                        key={index}
                        className="main-menu__item fade-in-up-elm"
                        style={{ transitionDelay: `${index * 0.1}s` }}
                      >
                        {item.submenu ? (
                          <>
                            <div
                              className="main-menu__toggle"
                              onClick={() =>
                                setActiveSubmenu((pre) =>
                                  pre == index ? -1 : index
                                )
                              }
                            >
                              <AnimatedButton
                                text={item.title}
                                as="span"
                                className="main-menu__link btn btn-anim"
                              ></AnimatedButton>
                              <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width={20}
                                height={20}
                                viewBox="0 0 20 20"
                              >
                                <path d="M19.6,9.6h-3.9c-.4,0-1.8-.2-1.8-.2-.6,0-1.1-.2-1.6-.6-.5-.3-.9-.8-1.2-1.2-.3-.4-.4-.9-.5-1.4,0,0,0-1.1-.2-1.5V.4c0-.2-.2-.4-.4-.4s-.4.2-.4.4v4.4c0,.4-.2,1.5-.2,1.5,0,.5-.2,1-.5,1.4-.3.5-.7.9-1.2,1.2s-1,.5-1.6.6c0,0-1.2,0-1.7.2H.4c-.2,0-.4.2-.4.4s.2.4.4.4h4.1c.4,0,1.7.2,1.7.2.6,0,1.1.2,1.6.6.4.3.8.7,1.1,1.1.3.5.5,1,.6,1.6,0,0,0,1.3.2,1.7v4.1c0,.2.2.4.4.4s.4-.2.4-.4v-4.1c0-.4.2-1.7.2-1.7,0-.6.2-1.1.6-1.6.3-.4.7-.8,1.1-1.1.5-.3,1-.5,1.6-.6,0,0,1.3,0,1.8-.2h3.9c.2,0,.4-.2.4-.4s-.2-.4-.4-.4Z" />
                              </svg>
                            </div>
                            <ul
                              className="submenu"
                              style={{
                                height:
                                  activeSubmenu === index
                                    ? `calc(${submenuHeights[index]}px + 2rem)`
                                    : 0,
                                paddingTop:
                                  activeSubmenu === index ? "2rem" : 0,
                                transition: "all 0.3s ease",
                              }}
                              ref={(el) => {
                                submenuRefs.current[index] = el;
                              }}
                            >
                              {item.submenu.map((sub, i) => (
                                <li
                                  key={i}
                                  className={`submenu__item ${
                                    isMenuActive(sub.href) ? "active" : ""
                                  }`}
                                >
                                  <Link href={sub.href}>{sub.label}</Link>
                                </li>
                              ))}
                            </ul>
                          </>
                        ) : (
                          <>
                            {item.href ? (
                              <AnimatedButton
                                text={item.title}
                                className="main-menu__link btn btn-anim"
                                href={item.href}
                              ></AnimatedButton>
                            ) : (
                              ""
                            )}
                          </>
                        )}
                      </li>
                    ))}
                  </ul>
                </nav>
              </div>
            </div>
            {/* right side (promo do template removido na Fase 1) */}
            {/* data bottom line */}
            <div
              className="mxd-menu__data fade-in-up-elm"
              style={{ transitionDelay: "0.4s" }}
            >
              <p className="t-xsmall">
                <i className="ph ph-copyright" />
                {new Date().getFullYear()} XPLENDOR
              </p>
            </div>
          </div>
          <div className="hamburger__parking-slot" />
        </div>
      </div>
      {/* Main Navigation End */}
    </nav>
  );
}
