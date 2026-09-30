"use client";
import Link from "next/link";
import Image from "next/image";
import { useEffect, useRef } from "react";
import { gsap } from "gsap";
import { ScrollTrigger } from "gsap/ScrollTrigger";
import { projects5 } from "@/data/projects.json";
gsap.registerPlugin(ScrollTrigger);
import VelocityMarquee from "@/components/animation/VelocityMarquee";

export default function Hero() {
  const staticAnimRefs = useRef<Array<HTMLDivElement | null>>([]);
  const fadeOutRefs = useRef<Array<HTMLDivElement | null>>([]);

  useEffect(() => {
    // Static Anim Elements (scale + fade out)
    staticAnimRefs.current.forEach((el) => {
      if (el) {
        gsap
          .timeline({
            scrollTrigger: {
              trigger: ".hero-02-static__tl-trigger",
              start: "top 14%",
              end: "top 0.2%",
              scrub: true,
            },
            ease: "sine",
          })
          .fromTo(
            el,
            { transform: "translate3d(0, 0, 0)", scaleY: 1, opacity: 1 },
            { transform: "translate3d(0, -5rem, 0)", scaleY: 1.3, opacity: 0 }
          );
      }
    });

    // Fade Out Elements
    fadeOutRefs.current.forEach((el) => {
      if (el) {
        gsap
          .timeline({
            scrollTrigger: {
              trigger: ".mxd-pinned-fullscreen__tl-trigger",
              start: "top 80%",
              end: "top 10%",
              scrub: true,
            },
            ease: "sine",
          })
          .fromTo(el, { opacity: 1 }, { opacity: 0 });
      }
    });

    // Cleanup triggers
    return () => {
      ScrollTrigger.getAll().forEach((trigger) => trigger.kill());
    };
  }, []);
  // group images by row
  const grouped = projects5.reduce<
    Record<string, (typeof projects5)[number][]>
  >((acc, item) => {
    acc[item.row] = acc[item.row] || [];
    acc[item.row].push(item);
    return acc;
  }, {});
  return (
    <div className="mxd-section">
      <div className="mxd-hero-02 mxd-pinned-fullscreen">
        <div
          className="mxd-pinned-fullscreen__static hero-02-fade-out-scroll loading-wrap"
          ref={(el) => {
            fadeOutRefs.current[0] = el;
          }}
        >
          <div className="hero-02-static__tl-trigger" />
          {/* static top */}
          <div
            className="mxd-hero-02-static__top hero-02-static-anim-el"
            ref={(el) => {
              staticAnimRefs.current[0] = el;
            }}
          >
            <div className="mxd-container fullwidth-container grid-container">
              <div className="container-fluid p-0">
                <div className="row g-0">
                  <div className="col-12 col-xl-6 mxd-grid-item no-margin">
                    <div className="hero-02-static__caption loading__item">
                      <p className="t-large t-medium t-120 t-bright">
                        Powering next gen projects.
                      </p>
                      <p className="t-large t-medium t-120 t-muted">
                        No creative limits.
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div className="mxd-hero-02-static__center">
            <div className="mxd-hero-02-marquee">
              <div className="mxd-hero-02-marquee__image loading__item">
                <Image
                  className="mxd-move"
                  alt="Hero Image"
                  src="/img/hero/03_hero-img.webp"
                  width={700}
                  height={704}
                />
              </div>
              <div className="mxd-hero-02-marquee__line loading__item">
                <VelocityMarquee
                  direction="left"
                  className="marquee marquee-left--gsap"
                >
                  {/* single item */}
                  <div className="marquee__item one-line item-regular text">
                    <p className="marquee__text">the power of imagination</p>
                    <div className="marquee__image">
                      <svg
                        className="mxd-pulse"
                        version="1.1"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 80 80"
                        fill="currentColor"
                      >
                        <path
                          fill="currentColor"
                          d="M78.4,38.4c0,0-11.8,0-15.8,0c-1.6,0-4.8-0.2-7.1-0.8c-2.3-0.6-4.3-0.8-6.3-2.4c-2-1.2-3.5-3.2-4.7-4.8
                        c-1.2-1.6-1.6-3.6-2-5.5c-0.3-1.5-0.7-4.3-0.8-5.9c-0.2-4.3,0-17.4,0-17.4C41.8,0.8,41,0,40.2,0s-1.6,0.8-1.6,1.6c0,0,0,13.1,0,17.4
                        c0,1.6-0.6,4.3-0.8,5.9c-0.3,2-0.8,4-2,5.5c-1.2,2-2.8,3.6-4.7,4.8s-4,1.8-6.3,2.4c-1.9,0.5-4.7,0.6-6.7,0.8c-3.9,0.4-16.6,0-16.6,0
                        C0.8,38.4,0,39.2,0,40c0,0.8,0.8,1.6,1.6,1.6c0,0,12.2,0,16.6,0c1.6,0,4.8,0.3,6.7,0.8c2.3,0.6,4.3,0.8,6.3,2.4
                        c1.6,1.2,3.2,2.8,4.3,4.4c1.2,2,2.1,3.9,2.4,6.3c0.2,1.7,0.7,4.7,0.8,6.7c0.2,4,0,16.2,0,16.2c0,0.8,0.8,1.6,1.6,1.6
                        s1.6-0.8,1.6-1.6c0,0,0-12.3,0-16.2c0-1.6,0.5-5.1,0.8-6.7c0.5-2.3,0.8-4.4,2.4-6.3c1.2-1.6,2.8-3.2,4.3-4.4c2-1.2,3.9-2,6.3-2.4
                        c1.8-0.3,5.1-0.7,7.1-0.8c3.5-0.2,15.8,0,15.8,0c0.8,0,1.6-0.8,1.6-1.6C80,39.2,79.2,38.4,78.4,38.4C78.4,38.4,78.4,38.4,78.4,38.4z
                        "
                        />
                      </svg>
                    </div>
                  </div>
                  {/* single item */}
                  <div className="marquee__item one-line item-regular text">
                    <p className="marquee__text">the power of imagination</p>
                    <div className="marquee__image">
                      <svg
                        className="mxd-pulse"
                        version="1.1"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 80 80"
                        fill="currentColor"
                      >
                        <path
                          fill="currentColor"
                          d="M78.4,38.4c0,0-11.8,0-15.8,0c-1.6,0-4.8-0.2-7.1-0.8c-2.3-0.6-4.3-0.8-6.3-2.4c-2-1.2-3.5-3.2-4.7-4.8
                        c-1.2-1.6-1.6-3.6-2-5.5c-0.3-1.5-0.7-4.3-0.8-5.9c-0.2-4.3,0-17.4,0-17.4C41.8,0.8,41,0,40.2,0s-1.6,0.8-1.6,1.6c0,0,0,13.1,0,17.4
                        c0,1.6-0.6,4.3-0.8,5.9c-0.3,2-0.8,4-2,5.5c-1.2,2-2.8,3.6-4.7,4.8s-4,1.8-6.3,2.4c-1.9,0.5-4.7,0.6-6.7,0.8c-3.9,0.4-16.6,0-16.6,0
                        C0.8,38.4,0,39.2,0,40c0,0.8,0.8,1.6,1.6,1.6c0,0,12.2,0,16.6,0c1.6,0,4.8,0.3,6.7,0.8c2.3,0.6,4.3,0.8,6.3,2.4
                        c1.6,1.2,3.2,2.8,4.3,4.4c1.2,2,2.1,3.9,2.4,6.3c0.2,1.7,0.7,4.7,0.8,6.7c0.2,4,0,16.2,0,16.2c0,0.8,0.8,1.6,1.6,1.6
                        s1.6-0.8,1.6-1.6c0,0,0-12.3,0-16.2c0-1.6,0.5-5.1,0.8-6.7c0.5-2.3,0.8-4.4,2.4-6.3c1.2-1.6,2.8-3.2,4.3-4.4c2-1.2,3.9-2,6.3-2.4
                        c1.8-0.3,5.1-0.7,7.1-0.8c3.5-0.2,15.8,0,15.8,0c0.8,0,1.6-0.8,1.6-1.6C80,39.2,79.2,38.4,78.4,38.4C78.4,38.4,78.4,38.4,78.4,38.4z
                        "
                        />
                      </svg>
                    </div>
                  </div>
                  {/* single item */}
                  <div className="marquee__item one-line item-regular text">
                    <p className="marquee__text">the power of imagination</p>
                    <div className="marquee__image">
                      <svg
                        className="mxd-pulse"
                        version="1.1"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 80 80"
                        fill="currentColor"
                      >
                        <path
                          fill="currentColor"
                          d="M78.4,38.4c0,0-11.8,0-15.8,0c-1.6,0-4.8-0.2-7.1-0.8c-2.3-0.6-4.3-0.8-6.3-2.4c-2-1.2-3.5-3.2-4.7-4.8
                        c-1.2-1.6-1.6-3.6-2-5.5c-0.3-1.5-0.7-4.3-0.8-5.9c-0.2-4.3,0-17.4,0-17.4C41.8,0.8,41,0,40.2,0s-1.6,0.8-1.6,1.6c0,0,0,13.1,0,17.4
                        c0,1.6-0.6,4.3-0.8,5.9c-0.3,2-0.8,4-2,5.5c-1.2,2-2.8,3.6-4.7,4.8s-4,1.8-6.3,2.4c-1.9,0.5-4.7,0.6-6.7,0.8c-3.9,0.4-16.6,0-16.6,0
                        C0.8,38.4,0,39.2,0,40c0,0.8,0.8,1.6,1.6,1.6c0,0,12.2,0,16.6,0c1.6,0,4.8,0.3,6.7,0.8c2.3,0.6,4.3,0.8,6.3,2.4
                        c1.6,1.2,3.2,2.8,4.3,4.4c1.2,2,2.1,3.9,2.4,6.3c0.2,1.7,0.7,4.7,0.8,6.7c0.2,4,0,16.2,0,16.2c0,0.8,0.8,1.6,1.6,1.6
                        s1.6-0.8,1.6-1.6c0,0,0-12.3,0-16.2c0-1.6,0.5-5.1,0.8-6.7c0.5-2.3,0.8-4.4,2.4-6.3c1.2-1.6,2.8-3.2,4.3-4.4c2-1.2,3.9-2,6.3-2.4
                        c1.8-0.3,5.1-0.7,7.1-0.8c3.5-0.2,15.8,0,15.8,0c0.8,0,1.6-0.8,1.6-1.6C80,39.2,79.2,38.4,78.4,38.4C78.4,38.4,78.4,38.4,78.4,38.4z
                        "
                        />
                      </svg>
                    </div>
                  </div>
                </VelocityMarquee>
              </div>
            </div>
          </div>
          <div
            className="mxd-hero-02-static__bottom hero-02-static-anim-el"
            ref={(el) => {
              staticAnimRefs.current[1] = el;
            }}
          >
            <div className="mxd-container fullwidth-container grid-container">
              <div className="container-fluid p-0">
                <div className="row g-0">
                  <div className="col-12 col-md-6 col-xl-4 mxd-grid-item no-margin">
                    <div className="mxd-paragraph__lists loading__fade">
                      <div className="container-fluid p-0">
                        <div className="row g-0">
                          <div className="col-6 col-xl-5">
                            <ul>
                              <li>
                                <p className="t-small anim-uni-in-up">
                                  Innovations
                                </p>
                              </li>
                              <li>
                                <p className="t-small anim-uni-in-up">
                                  Creativity
                                </p>
                              </li>
                              <li>
                                <p className="t-small anim-uni-in-up">
                                  Experience
                                </p>
                              </li>
                              <li>
                                <p className="t-small anim-uni-in-up">
                                  Competence
                                </p>
                              </li>
                              <li>
                                <p className="t-small anim-uni-in-up">
                                  Passion
                                </p>
                              </li>
                            </ul>
                          </div>
                          <div className="col-6 col-xl-5">
                            <ul>
                              <li>
                                <p className="t-small anim-uni-in-up">IU/UX</p>
                              </li>
                              <li>
                                <p className="t-small anim-uni-in-up">
                                  App design
                                </p>
                              </li>
                              <li>
                                <p className="t-small anim-uni-in-up">
                                  Development
                                </p>
                              </li>
                              <li>
                                <p className="t-small anim-uni-in-up">
                                  Branding
                                </p>
                              </li>
                              <li>
                                <p className="t-small anim-uni-in-up">Motion</p>
                              </li>
                            </ul>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div
            className="mxd-hero-02-static__btn hero-02-static-anim-el loading__fade"
            ref={(el) => {
              staticAnimRefs.current[2] = el;
            }}
          >
            <a href="#" className="btn-rotating btn-rotating-160">
              {/* SVG rotating text */}
              <svg
                version="1.1"
                id="scrollDown"
                xmlns="http://www.w3.org/2000/svg"
                xmlnsXlink="http://www.w3.org/1999/xlink"
                x="0px"
                y="0px"
                viewBox="0 0 160 160"
                enableBackground={"new 0 0 160 160"}
                xmlSpace="preserve"
                className="btn-rotating__text mxd-rotate"
                data-value={360}
              >
                <defs>
                  <path
                    id="textPath"
                    d="M149.7,80c0,38.5-31.2,69.7-69.7,69.7S10.3,118.5,10.3,80S41.5,10.3,80,10.3S149.7,41.5,149.7,80z"
                  />
                </defs>
                <g>
                  <use xlinkHref="#textPath" fill="none" />
                  <text>
                    {/* button text here!!! */}
                    <textPath xlinkHref="#textPath">
                      Scroll for More * Scroll for More * Scroll for More *
                    </textPath>
                  </text>
                </g>
              </svg>
              {/* image */}
              {/* <Image class="btn-rotating__image" src="/img/icons/60x60_obj-btn-02.svg" alt="Object"> */}
              <svg
                className="btn-rotating__image"
                version="1.1"
                xmlns="http://www.w3.org/2000/svg"
                xmlnsXlink="http://www.w3.org/1999/xlink"
                x="0px"
                y="0px"
                width="60px"
                height="60px"
                viewBox="0 0 60 60"
                enableBackground={"new 0 0 60 60"}
                xmlSpace="preserve"
              >
                <style
                  type="text/css"
                  dangerouslySetInnerHTML={{
                    __html:
                      "\n                    .icon-star-scroll {\n                      fill: var(--accent);\n                    }\n                  ",
                  }}
                />
                <path
                  className="icon-star-scroll"
                  d="M58.9,28.9c0,0-9.1,0.1-12.1,0c-1.3,0-5.3-0.5-5.3-0.5c-1.7-0.2-3.4-0.7-4.8-1.7c-1.4-1-2.7-2.3-3.6-3.7
            c-0.8-1.3-1.3-2.7-1.5-4.2c0,0-0.4-3.3-0.5-4.4c-0.2-3.3,0-13.1,0-13.1c0-0.6-0.5-1.1-1.1-1.1s-1.1,0.5-1.1,1.1
            c0,0,0.2,9.8,0,13.1c0,1.1-0.5,4.4-0.5,4.4c-0.2,1.5-0.6,3-1.5,4.2c-0.9,1.5-2.2,2.7-3.6,3.7s-3,1.5-4.7,1.7c0,0-3.7,0.4-5,0.5
            c-3.1,0.2-12.5,0-12.5,0C0.5,28.9,0,29.4,0,30s0.5,1.1,1.1,1.1c0,0,9.4-0.2,12.5,0c1.2,0,5,0.5,5,0.5c1.7,0.2,3.3,0.7,4.7,1.7
            c1.3,0.9,2.4,2,3.3,3.3c1,1.4,1.5,3.1,1.7,4.8c0,0,0.4,3.9,0.5,5.2c0.1,3,0,12.2,0,12.2c0,0.6,0.5,1.1,1.1,1.1s1.1-0.5,1.1-1.1
            c0,0-0.1-9.2,0-12.2c0-1.3,0.5-5.2,0.5-5.2c0.2-1.7,0.7-3.4,1.7-4.8c0.9-1.3,2-2.4,3.3-3.3c1.4-1,3.1-1.5,4.8-1.7
            c0,0,3.9-0.4,5.3-0.5c3-0.1,12.1,0,12.1,0c0.6,0,1.1-0.5,1.1-1.1s-0.5-1.1-1.1-1.1l0,0L58.9,28.9z"
                />
              </svg>
            </a>
          </div>
        </div>
        <div className="mxd-pinned-fullscreen__scroll">
          <div className="mxd-hero-02-scroll__wrap">
            <div className="mxd-hero-02-scroll__images">
              {Object.entries(grouped).map(([rowClass, items], rowIndex) => (
                <div key={rowIndex} className={rowClass}>
                  {items.map((item, idx) => (
                    <Link
                      key={idx}
                      href={`/project-details`}
                      className={item.wrapperClass}
                    >
                      <div
                        className={`mxd-hero-02-image__inner ${item.innerType} anim-uni-in-up`}
                      >
                        <Image
                          alt="Hero Image"
                          src={item.src}
                          width={item.width}
                          height={item.height}
                        />
                        <div className="mxd-preview-hover">
                          <i className="mxd-preview-hover__icon icon-small">
                            <Image
                              alt="Eye Icon"
                              src="/img/icons/icon-eye.svg"
                              width={38}
                              height={21}
                            />
                          </i>
                        </div>
                      </div>
                    </Link>
                  ))}
                </div>
              ))}
            </div>
          </div>
        </div>
        <div className="mxd-pinned-fullscreen__tl-trigger" />
      </div>
    </div>
  );
}
