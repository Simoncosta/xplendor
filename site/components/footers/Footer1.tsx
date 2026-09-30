import Link from "next/link";
import Image from "next/image";

import AnimatedButton from "../animation/AnimatedButton";

export default function Footer1() {
  const footerNavData = [
    {
      title: "Start pages",
      links: [
        { label: "Main home", href: "/home-main" },
        {
          label: "Software development company",
          href: "/home-software-development-company",
        },
        { label: "Freelancer portfolio", href: "/home-freelancer-portfolio" },
        { label: "Digital agency", href: "/home-digital-agency" },
        {
          label: "Creative design studio",
          href: "/home-creative-design-studio",
        },
        { label: "Personal portfolio", href: "/home-personal-portfolio" },
        { label: "Web agency", href: "/home-web-agency" },
        { label: "Creative developer", href: "/home-creative-developer" },
        { label: "Designer", href: "/home-designer" },
      ],
    },
    {
      // Combined column: Portfolio + Insights Pages
      groups: [
        {
          title: "Portfolio",
          links: [
            { label: "Works simple", href: "/works-simple" },
            { label: "Works masonry", href: "/works-masonry" },
            { label: "Project details", href: "/project-details" },
          ],
        },
        {
          title: "Insights pages",
          links: [
            { label: "Blog standard", href: "/blog-standard" },
            { label: "Blog creative", href: "/blog-creative" },
            { label: "Single post", href: "/blog-article" },
          ],
        },
      ],
    },
    {
      title: "Inner pages",
      links: [
        { label: "About me", href: "/about-me" },
        { label: "About us", href: "/about-us" },
        { label: "Services", href: "/services" },
        { label: "Our team", href: "/team" },
        { label: "Pricing plans", href: "/pricing" },
        { label: "Frequently asked questions", href: "/faq" },
        { label: "404 error page", href: "/404" },
        { label: "Contact", href: "/contact" },
      ],
    },
  ];

  return (
    <footer className="mxd-demo-footer">
      {/* Footer Background Start */}
      <div className="mxd-demo-footer__bg">
        <Image
          alt="Rayo Image"
          width={1920}
          height={580}
          src="/img/demo/01-footer.webp"
        />
      </div>
      {/* Footer Background End */}
      {/* Footer Container Start */}
      <div className="mxd-container grid-container">
        {/* Footer Block - Content Block Start */}
        <div className="mxd-block">
          <div className="container-fluid p-0">
            <div className="row g-0">
              <div className="col-12 col-xl-3 mxd-demo-footer__item mxd-grid-item">
                <div className="mxd-demo-footer__logo anim-uni-in-up">
                  <a href="#" className="mxd-logo">
                    {/* logo icon */}
                    <svg
                      className="mxd-logo__image"
                      version="1.1"
                      xmlns="http://www.w3.org/2000/svg"
                      x="0px"
                      y="0px"
                      viewBox="0 0 56 56"
                      enableBackground={"new 0 0 56 56"}
                      xmlSpace="preserve"
                    >
                      <style
                        type="text/css"
                        dangerouslySetInnerHTML={{
                          __html:
                            "\n                        .mxd-logo__bg {\n                          fill: var(--base-opp);\n                        }\n                        .mxd-logo__cat {\n                          clip-path: url(#mxd-logo__id-2);\n                          fill: var(--base);\n                        }\n                      ",
                        }}
                      />
                      <path
                        className="mxd-logo__bg"
                        d="M56,28c0,11.1-2.9,28-28,28S0,39.1,0,28S2.9,0,28,0S56,16.9,56,28z"
                      />
                      <g>
                        <defs>
                          <path
                            id="mxd-logo__clippath-2"
                            d="M28,0C2.9,0,0,16.9,0,28s2.9,28,28,28s28-16.9,28-28S53.1,0,28,0z"
                          />
                        </defs>
                        <clipPath id="mxd-logo__id-2">
                          <use
                            xlinkHref="#mxd-logo__clippath-2"
                            style={{ overflow: "visible" }}
                          />
                        </clipPath>
                        <path
                          className="mxd-logo__cat"
                          d="M33.6,34.5h0.9
                    c0.5,0,0.9,0.4,0.9,0.9v3.7c0,0.5-0.4,0.9-0.9,0.9h-0.9c-0.5,0-0.9-0.4-0.9-0.9v-3.7C32.7,34.9,33.1,34.5,33.6,34.5z M20.5,37.3
                    v1.9c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9-0.4,0.9-0.9v-3.7c0-0.5-0.4-0.9-0.9-0.9h-0.9c-0.5,0-0.9,0.4-0.9,0.9V37.3L20.5,37.3z
                    M39.2,21.5v0.9c0,0.5-0.4,0.9-0.9,0.9h-0.9c-0.5,0-0.9-0.4-0.9-0.9v-0.9c0-0.5,0.4-0.9,0.9-0.9h0.9C38.8,20.5,39.2,21,39.2,21.5z
                    M34.5,26.1h0.9c0.5,0,0.9-0.4,0.9-0.9v-0.9c0-0.5-0.4-0.9-0.9-0.9h-0.9c-0.5,0-0.9,0.4-0.9,0.9v0.9C33.6,25.7,34,26.1,34.5,26.1z
                    M28,26.1h-4.7c-0.5,0-0.9,0.4-0.9,0.9V28c0,0.5,0.4,0.9,0.9,0.9h9.3c0.5,0,0.9-0.4,0.9-0.9v-0.9c0-0.5-0.4-0.9-0.9-0.9H28L28,26.1
                    z M19.6,24.3v0.9c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9-0.4,0.9-0.9v-0.9c0-0.5-0.4-0.9-0.9-0.9h-0.9C20,23.3,19.6,23.8,19.6,24.3z
                    M16.8,21.5v0.9c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9-0.4,0.9-0.9v-0.9c0-0.5-0.4-0.9-0.9-0.9h-0.9C17.2,20.5,16.8,21,16.8,21.5z
                    M14,26.1v4.7c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9-0.4,0.9-0.9v-6.5c0-0.5-0.4-0.9-0.9-0.9h-0.9c-0.5,0-0.9,0.4-0.9,0.9V26.1
                    L14,26.1z M11.2,34.5v1.9c0,0.5-0.4,0.9-0.9,0.9H7.5c-0.5,0-0.9,0.4-0.9,0.9v0.9c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9,0.4,0.9,0.9
                    V42c0,0.5-0.4,0.9-0.9,0.9H7.5c-0.5,0-0.9,0.4-0.9,0.9v0.9c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9,0.4,0.9,0.9V56
                    c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9-0.4,0.9-0.9v-6.5c0-0.5,0.4-0.9,0.9-0.9h3.7c0.5,0,0.9-0.4,0.9-0.9v-0.9
                    c0-0.5-0.4-0.9-0.9-0.9h-3.7c-0.5,0-0.9-0.4-0.9-0.9v-6.5c0-0.5,0.4-0.9,0.9-0.9c0.5,0,0.9-0.4,0.9-0.9v-3.7c0-0.5-0.4-0.9-0.9-0.9
                    h-0.9c-0.5,0-0.9,0.4-0.9,0.9L11.2,34.5L11.2,34.5z M42,26.1v-1.9c0-0.5-0.4-0.9-0.9-0.9h-0.9c-0.5,0-0.9,0.4-0.9,0.9v6.5
                    c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9-0.4,0.9-0.9V26.1L42,26.1z M49.5,39.2v-0.9c0-0.5-0.4-0.9-0.9-0.9h-2.8
                    c-0.5,0-0.9-0.4-0.9-0.9v-3.7c0-0.5-0.4-0.9-0.9-0.9h-0.9c-0.5,0-0.9,0.4-0.9,0.9v3.7c0,0.5,0.4,0.9,0.9,0.9c0.5,0,0.9,0.4,0.9,0.9
                    v6.5c0,0.5-0.4,0.9-0.9,0.9h-3.7c-0.5,0-0.9,0.4-0.9,0.9v0.9c0,0.5,0.4,0.9,0.9,0.9h3.7c0.5,0,0.9,0.4,0.9,0.9V56
                    c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9-0.4,0.9-0.9v-9.3c0-0.5,0.4-0.9,0.9-0.9h0.9c0.5,0,0.9-0.4,0.9-0.9v-0.9
                    c0-0.5-0.4-0.9-0.9-0.9h-0.9c-0.5,0-0.9-0.4-0.9-0.9v-0.9c0-0.5,0.4-0.9,0.9-0.9h0.9C49,40.1,49.5,39.7,49.5,39.2L49.5,39.2z"
                        />
                      </g>
                    </svg>
                    {/* logo text */}
                    <span className="mxd-logo__text">
                      rayo
                      <br />
                      template
                    </span>
                  </a>
                </div>
                <div className="mxd-demo-footer__slogan anim-uni-in-up">
                  <p className="t-small t-bright">
                    👋 New Rayo template is here! Showcase your projects,
                    services and expertise with impact.
                  </p>
                </div>
                <div className="mxd-demo-footer__btn anim-uni-in-up">
                  <AnimatedButton
                    text="Buy Now"
                    as={"a"}
                    className="btn btn-anim btn-default btn-small btn-accent slide-right"
                    href="https://themeforest.net/item/rayo-digital-agency-personal-portfolio-nextjs-template/60468451"
                    target="_blank"
                  >
                    <i className="ph ph-shopping-cart-simple" />
                  </AnimatedButton>
                  <AnimatedButton
                    text="Demo"
                    as={"a"}
                    className="btn btn-anim btn-default btn-small btn-outline slide-right-up"
                    href="#demo"
                  >
                    <i className="ph ph-arrow-up-right" />
                  </AnimatedButton>
                </div>
              </div>
              <div className="col-12 col-xl-6 mxd-demo-footer__item">
                <nav className="mxd-demo-footer__nav">
                  <div className="container-fluid p-0">
                    <div className="row g-0">
                      {footerNavData.map((section, index) => (
                        <div
                          key={index}
                          className="col-12 col-md-4 mxd-grid-item mxd-footer-nav__item"
                        >
                          {/* Check if this section contains grouped blocks */}
                          {section.groups ? (
                            section.groups.map((group, gIndex) => (
                              <div
                                key={gIndex}
                                className="mxd-footer-nav__block"
                              >
                                <div className="mxd-footer-nav__title anim-uni-in-up">
                                  <p className="t-140 t-bright t-caption">
                                    {group.title}
                                  </p>
                                </div>
                                <div className="mxd-footer-nav__list">
                                  <ul>
                                    {group.links.map((link, lIndex) => (
                                      <li key={lIndex}>
                                        <Link
                                          className="anim-uni-in-up"
                                          href={link.href}
                                          target="_blank"
                                        >
                                          {link.label}
                                        </Link>
                                      </li>
                                    ))}
                                  </ul>
                                </div>
                              </div>
                            ))
                          ) : (
                            <div className="mxd-footer-nav__block">
                              <div className="mxd-footer-nav__title anim-uni-in-up">
                                <p className="t-140 t-bright t-caption">
                                  {section.title}
                                </p>
                              </div>
                              <div className="mxd-footer-nav__list">
                                <ul>
                                  {section.links.map((link, lIndex) => (
                                    <li key={lIndex}>
                                      <Link
                                        className="anim-uni-in-up"
                                        href={link.href}
                                        target="_blank"
                                      >
                                        {link.label}
                                      </Link>
                                    </li>
                                  ))}
                                </ul>
                              </div>
                            </div>
                          )}
                        </div>
                      ))}
                    </div>
                  </div>
                </nav>
              </div>
              <div className="col-12 col-xl-3 mxd-demo-footer__item mxd-grid-item">
                <div className="mxd-demo-footer__promo">
                  <div className="mxd-footer-promo__title anim-uni-in-up">
                    <p className="t-140 t-bright t-caption">
                      More by ib-themes:
                    </p>
                  </div>
                  <div className="mxd-footer-promo__list">
                    <a
                      className="mxd-footer-promo__item"
                      href="https://themeforest.net/item/resonance-multipurpose-onemulti-page-react-nextjs-template/53612924"
                      target="_blank"
                    >
                      <Image
                        className="mxd-footer-promo__icon anim-uni-in-up"
                        alt="Blayden - Personal Portfolio & Resume React Nextjs Template"
                        width={180}
                        height={180}
                        src="/img/demo/icon-blayden.png"
                      />
                      <span className="mxd-footer-promo__link anim-uni-in-up">
                        Resonance - Multipurpose Creative NextJs Templates
                      </span>
                    </a>
                    <a
                      className="mxd-footer-promo__item"
                      href="https://themeforest.net/item/sandbox-modern-multipurpose-tailwind-css-template/51340309"
                      target="_blank"
                    >
                      <Image
                        className="mxd-footer-promo__icon anim-uni-in-up"
                        alt="Ignite - Portfolio and Landing Page Template"
                        width={180}
                        height={180}
                        src="/img/demo/icon-ignite.png"
                      />
                      <span className="mxd-footer-promo__link anim-uni-in-up">
                        Sandbox - Tailwind CSS Modern & Multipurpose Template
                      </span>
                    </a>
                    <a
                      className="mxd-footer-promo__item"
                      href="https://themeforest.net/item/gotrip-travel-tour-online-booking-nextjs-template/44047013"
                      target="_blank"
                    >
                      <Image
                        className="mxd-footer-promo__icon anim-uni-in-up"
                        alt="Braxton - Personal Portfolio & Resume React Nextjs Template"
                        width={500}
                        height={500}
                        src="/img/demo/icon-braxton.png"
                      />
                      <span className="mxd-footer-promo__link anim-uni-in-up">
                        GoTrip - Travel & Tour Agency React NextJs Template
                      </span>
                    </a>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        {/* Footer Block - Content Block End */}
        {/* Footer Block - Fullwidth Text Start */}
        <div className="mxd-block">
          <div className="mxd-demo-footer__mixdesign mxd-grid-item no-margin">
            <a
              className="anim-uni-in-up"
              href="https://themeforest.net/item/rayo-digital-agency-personal-portfolio-nextjs-template/60468451"
              target="_blank"
            >
              <svg
                xmlns="http://www.w3.org/2000/svg"
                version="1.1"
                viewBox="0 0 400 74"
                fill="currentColor"
              >
                <path d="M283.7,33.4h10.1v40.6h-10.1v-40.6ZM303.9,64.2c-1.5-3.1-2.3-6.8-2.3-11,0-6.3,1.7-11.4,5.2-15.1,3.5-3.7,8.2-5.6,14.3-5.6s6.2.6,8.6,1.9c2.4,1.3,4.3,3.1,5.8,5.4h2.8v-6.5h10.4v10.1h-5.1v29.6c0,.3,0,.6,0,.9h-9.8c0-.4,0-.7,0-1.1v-5.8h-.3c-1.2,2.3-2.9,4-5.1,5.1-2.2,1.2-4.9,1.8-7.9,1.8s-7.1-.8-10-2.5c-2.8-1.7-5-4.1-6.6-7.2ZM311.7,53.3c0,3.8,1,6.7,2.9,8.8,2,2.1,4.7,3.1,8.3,3.1s5.8-.9,7.7-2.7c1.9-1.8,2.8-4.3,2.8-7.4v-3.7c0-3.1-1-5.5-2.9-7.3-1.9-1.8-4.5-2.7-7.7-2.7s-6.3,1-8.3,3.1c-2,2.1-2.9,5-2.9,8.8ZM358.7,74h10.1v-22.6c0-3.2.9-5.6,2.6-7.3,1.7-1.7,4.2-2.5,7.5-2.5s5.9.8,7.5,2.5c1.7,1.7,2.5,4.1,2.5,7.3v22.6h10.1v-24.5c0-5.1-1.5-9.2-4.6-12.3-3-3.1-7.3-4.6-12.9-4.6s-6.4.6-8.8,1.9c-2.4,1.3-4.4,3-5.9,5.3h-2.8v-6.5h-10.4v10.1h5v30.5ZM81.5,74h10.1v-40.6h-10.1v40.6ZM288.7,28.9c1.6,0,2.9-.6,4-1.7,1.1-1.1,1.7-2.4,1.7-4s-.6-2.9-1.7-4c-1.1-1.1-2.4-1.7-4-1.7s-2.9.6-4,1.7c-1.1,1.1-1.7,2.4-1.7,4s.6,2.9,1.7,4c1.1,1.1,2.4,1.7,4,1.7ZM86.5,28.9c1.6,0,2.9-.6,4-1.7,1.1-1.1,1.7-2.4,1.7-4s-.6-2.9-1.7-4c-1.1-1.1-2.4-1.7-4-1.7s-2.9.6-4,1.7-1.7,2.4-1.7,4,.6,2.9,1.7,4c1.1,1.1,2.4,1.7,4,1.7ZM107.7,74l10.6-13.9,10.5,13.9h11.3l-15.9-20.8,15.1-19.8h-11.1l-9.8,12.8-9.8-12.8h-11.2l15.1,19.8-15.9,20.8h11.2ZM259.4,67c-3.1,0-5.4-.5-7-1.5-1.6-1-2.6-2.6-2.9-4.7h-9.8c.3,4.4,2.1,7.9,5.5,10.3,1.8,1.3,4,2.3,6.5,2.9h14.7c2.3-.6,4.2-1.5,5.8-2.6,3-2.3,4.5-5.5,4.5-9.6s-1-5.8-2.9-7.7c-1.9-1.9-5-3.3-9.1-4.1l-9.1-1.8c-1.7-.3-2.9-.8-3.7-1.4-.8-.6-1.2-1.5-1.2-2.5s.6-2.5,1.8-3.2c1.2-.7,3-1.1,5.3-1.1s4.5.5,5.9,1.4c1.4.9,2.3,2.4,2.8,4.4h9.8c-.4-4.2-2.1-7.5-5.2-9.9-3.1-2.3-7.4-3.5-12.8-3.5s-9.4,1.1-12.5,3.4c-3,2.3-4.5,5.3-4.5,9.2,0,6,3.9,9.7,11.8,11.2l9,1.7c1.9.4,3.2.9,4,1.6.8.7,1.2,1.6,1.2,2.9,0,3-2.6,4.5-7.8,4.5ZM5,74h10.1v-22.8c0-3.2.8-5.6,2.3-7.2,1.5-1.6,3.8-2.4,6.8-2.4,6.2,0,9.3,3.2,9.3,9.6v22.8h10.1v-22.8c0-6.4,3-9.6,9.1-9.6s9.2,3.2,9.2,9.6v22.8h10.1v-24.6c0-5.1-1.5-9.2-4.5-12.2-3-3-7.1-4.5-12.3-4.5s-10.9,2.4-13.9,7.1h-.2c-1.4-2.3-3.3-4.1-5.6-5.3-2.3-1.2-5.1-1.8-8.3-1.8s-6.2.6-8.4,1.9c-2.3,1.3-4.1,3-5.5,5.3h-3v-6.5H0v10.1h5v30.5ZM144,64.8c-1.5-3.2-2.3-6.9-2.3-11.1s.7-8,2.2-11.2c1.5-3.2,3.6-5.6,6.4-7.3,2.8-1.7,6.1-2.6,10-2.6s5.7.6,7.9,1.7c2.2,1.2,3.9,2.8,5.1,5h.3v-22.1h10.1v46.8h5v10.1h-10.3v-6.5h-2.8c-1.4,2.3-3.4,4.2-5.8,5.4-.8.4-1.7.8-2.7,1.1h-12.3c-1.5-.4-3-1-4.2-1.8-2.9-1.7-5.1-4.2-6.6-7.4ZM151.8,53.7c0,3.9.9,6.9,2.8,9.1,1.9,2.2,4.6,3.3,8.3,3.3s5.8-.9,7.8-2.7,2.9-4.3,2.9-7.4v-4.5c0-3.1-1-5.6-2.9-7.4-1.9-1.8-4.5-2.7-7.8-2.7s-6.4,1.1-8.3,3.3c-1.9,2.2-2.8,5.2-2.8,9.1ZM196.1,64.8c-1.7-3.2-2.6-6.9-2.6-11.3s.9-8.1,2.6-11.2c1.7-3.1,4.2-5.6,7.3-7.2,3.2-1.7,6.9-2.5,11.1-2.5s7.7.8,10.7,2.4c3,1.6,5.3,3.9,6.9,6.9,1.6,3,2.4,6.6,2.4,10.7v3.5h-31.4c.4,3.4,1.6,6.1,3.6,7.9,2,1.8,4.7,2.7,8,2.7s4.5-.5,6.1-1.4c1.6-.9,2.7-2.3,3.3-4.2h10.2c-.9,4.3-3.1,7.6-6.5,10.1-1.8,1.3-3.9,2.2-6.2,2.8h-13.4c-1.7-.4-3.2-1-4.6-1.8-3.2-1.7-5.7-4.2-7.4-7.4ZM203.4,49.2h21.4c-.4-2.8-1.4-4.9-3.2-6.4-1.8-1.5-4.2-2.2-7.1-2.2s-5.3.7-7.2,2.2c-1.9,1.5-3.2,3.6-3.8,6.4Z" />
              </svg>
            </a>
          </div>
        </div>
        {/* Footer Block - Fullwidth Text End */}
      </div>
      {/* Footer Container End */}
    </footer>
  );
}
