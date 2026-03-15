import React, { useState, useEffect } from "react";
import { Collapse, Container, NavbarToggler, NavLink } from "reactstrap";
import Scrollspy from "react-scrollspy";
import { Link } from "react-router-dom";

import logodark from "../../assets/images/logo-dark.png";
import logolight from "../../assets/images/logo-light.png";

const whatsappNumber = "351938963526";
const whatsappUrl = `https://wa.me/${whatsappNumber}?text=${encodeURIComponent("Olá! Gostava de saber mais sobre a Xplendor.")}`;

const Navbar = () => {
    const [isOpenMenu, setisOpenMenu] = useState(false);
    const [navClass, setnavClass] = useState("");

    const toggle = () => setisOpenMenu(!isOpenMenu);

    useEffect(() => {
        window.addEventListener("scroll", scrollNavigation, true);
        return () => window.removeEventListener("scroll", scrollNavigation, true);
    }, []);

    const scrollNavigation = () => {
        if (document.documentElement.scrollTop > 50) {
            setnavClass("is-sticky");
        } else {
            setnavClass("");
        }
    };

    const [activeLink, setActiveLink] = useState<any>();

    useEffect(() => {
        const activation = (event: any) => {
            const target = event.target;
            if (target) {
                target.classList.add("active");
                setActiveLink(target);
                if (activeLink && activeLink !== target) {
                    activeLink.classList.remove("active");
                }
            }
        };
        const links = document.querySelectorAll(".navbar a");
        links.forEach(link => link.addEventListener("click", activation));
        return () => links.forEach(link => link.removeEventListener("click", activation));
    }, [activeLink]);

    return (
        <React.Fragment>
            <nav className={`navbar navbar-expand-lg navbar-landing fixed-top ${navClass}`} id="navbar">
                <Container>
                    <Link className="navbar-brand" to="/index">
                        <img src={logodark} className="card-logo card-logo-dark" alt="Xplendor" height="17" />
                        <img src={logolight} className="card-logo card-logo-light" alt="Xplendor" height="17" />
                    </Link>

                    <NavbarToggler
                        className="navbar-toggler py-0 fs-20 text-body"
                        onClick={toggle}
                    >
                        <i className="mdi mdi-menu"></i>
                    </NavbarToggler>

                    <Collapse isOpen={isOpenMenu} className="navbar-collapse" id="navbarSupportedContent">
                        <Scrollspy
                            offset={-18}
                            items={["hero", "services", "features", "plans", "faqs", "contact"]}
                            currentClassName="active"
                            className="navbar-nav mx-auto mt-2 mt-lg-0"
                            id="navbar-example"
                        >
                            <li className="nav-item">
                                <NavLink href="#hero" className="fs-15 fw-semibold">Início</NavLink>
                            </li>
                            <li className="nav-item">
                                <NavLink href="#services" className="fs-15 fw-semibold">Funcionalidades</NavLink>
                            </li>
                            <li className="nav-item">
                                <NavLink href="#features" className="fs-15 fw-semibold">Como funciona</NavLink>
                            </li>
                            <li className="nav-item">
                                <NavLink href="#plans" className="fs-15 fw-semibold">Planos</NavLink>
                            </li>
                            <li className="nav-item">
                                <NavLink href="#faqs" className="fs-15 fw-semibold">FAQ</NavLink>
                            </li>
                            <li className="nav-item">
                                <NavLink href="#contact" className="fs-15 fw-semibold">Contacto</NavLink>
                            </li>
                        </Scrollspy>

                        <div className="d-flex align-items-center gap-2">
                            <Link to="/login" className="btn btn-link fw-medium text-decoration-none text-body">
                                Entrar
                            </Link>
                            <a
                                href={whatsappUrl}
                                target="_blank"
                                rel="noreferrer"
                                className="btn btn-success"
                            >
                                <i className="ri-whatsapp-line me-1"></i>
                                WhatsApp
                            </a>
                        </div>
                    </Collapse>
                </Container>
            </nav>
        </React.Fragment>
    );
};

export default Navbar;