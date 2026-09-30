"use client";

import { useEffect, useRef } from "react";
import Image from "next/image";
import VelocityMarquee from "@/components/animation/VelocityMarquee";
import Counter from "@/components/common/Counter";

// GSAP
import { gsap } from "gsap";
import { ScrollTrigger } from "gsap/ScrollTrigger";
import AnimatedButton from "@/components/animation/AnimatedButton";
import { usePathname } from "next/navigation";
gsap.registerPlugin(ScrollTrigger);

export default function Hero() {
  const pathname = usePathname();
  const rootRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    if (!rootRef.current) return;

    // Scope GSAP selectors/tweens to this component
    const ctx = gsap.context(() => {
      const trigger = ".mxd-hero-07__tl-trigger";

      // slide-out elements
      gsap.utils
        .toArray<HTMLElement>(".hero-07-slide-out-scroll")
        .forEach((el) => {
          gsap
            .timeline({
              scrollTrigger: {
                trigger,
                start: "top 86%",
                end: "top 10%",
                scrub: true,
              },
            })
            .fromTo(
              el,
              { y: 0, scaleY: 1 },
              {
                y: "-26rem",
                scaleY: 0.8,
                ease: "power4.out",
              }
            );
        });

      // fade-out elements
      gsap.utils
        .toArray<HTMLElement>(".hero-07-fade-out-scroll")
        .forEach((el) => {
          gsap
            .timeline({
              scrollTrigger: {
                trigger,
                start: "top 70%",
                end: "top 40%",
                scrub: true,
              },
            })
            .fromTo(
              el,
              { opacity: 1, y: 0 },
              {
                opacity: 0,
                y: "-10rem",
                ease: "elastic.out(1, 0.3)",
              }
            );
        });
    }, rootRef);

    return () => ctx.revert(); // clean up timelines & ScrollTriggers
  }, [pathname]);
  return (
    <div className="mxd-section" ref={rootRef}>
      <div className="mxd-hero-07">
        <div className="mxd-hero-07__wrap loading-wrap">
          {/* top part */}
          <div className="mxd-hero-07__top">
            <h1 className="mxd-hero-07__title">
              Design. Development. Digital Art. Branding.
            </h1>
            <div className="mxd-hero-07__marquee loading__item">
              <VelocityMarquee className="marquee marquee-right--gsap bright">
                {/* single item */}
                <div className="marquee__item one-line item-regular text">
                  <p className="marquee__text">Design</p>
                  <div className="marquee__image">
                    <svg
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
                  <p className="marquee__text">Development</p>
                  <div className="marquee__image">
                    <svg
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
                  <p className="marquee__text">Digital Art</p>
                  <div className="marquee__image">
                    <svg
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
                  <p className="marquee__text">Branding</p>
                  <div className="marquee__image">
                    <svg
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
          {/* bottom part */}
          <div className="mxd-hero-07__bottom">
            <div className="mxd-hero-07__circle">
              <div className="hero-07-circle__image hero-07-slide-out-scroll loading__item">
                <Image
                  alt="Hero Image"
                  src="/img/hero/21_hero-img.webp"
                  width={1400}
                  height={1400}
                />
              </div>
              <div className="hero-07-circle__container hero-07-fade-out-scroll">
                <div className="hero-07-circle__item item-01 loading__item">
                  <div className="mxd-hero__mark">
                    <span className="mark-icon" />
                    <span className="mark-text">Available for freelance</span>
                  </div>
                </div>
              </div>
              <div className="hero-07-circle__container mobile-row hero-07-fade-out-scroll">
                <div className="hero-07-circle__item item-02 loading__item">
                  <div className="mxd-counter small">
                    <p
                      id="stats-counter-1"
                      className="mxd-counter__number mxd-stats-number small"
                    >
                      <Counter max={3} />+
                    </p>
                    <p className="mxd-counter__descr t-140 t-bright t-small">
                      Years of experience
                    </p>
                  </div>
                </div>
                <div className="hero-07-circle__item item-03 loading__item">
                  <div className="mxd-counter small">
                    <p
                      id="stats-counter-2"
                      className="mxd-counter__number mxd-stats-number small"
                    >
                      <Counter max={70} />+
                    </p>
                    <p className="mxd-counter__descr t-140 t-bright t-small">
                      Successful projects
                    </p>
                  </div>
                </div>
              </div>
            </div>
            <div className="mxd-hero-07__info loading__fade">
              <div className="hero-07-info__container">
                <div className="hero-07-info__descr">
                  <p className="t-large t-medium t-140 t-bright">
                    Hey! I&apos;m Alex Walker.
                    <br />
                    Digital artist, illustrator and creative developer.
                  </p>
                </div>
                <div className="hero-07-info__tags">
                  <span className="tag tag-default tag-outline-medium">
                    HTML
                  </span>
                  <span className="tag tag-default tag-outline-medium">
                    Frontend
                  </span>
                  <span className="tag tag-default tag-outline-medium">
                    Motion design
                  </span>
                  <span className="tag tag-default tag-outline-medium">
                    3d modeling
                  </span>
                  <span className="tag tag-default tag-outline-medium">
                    Video editing
                  </span>
                </div>
              </div>
              <div className="hero-07-info__container">
                <div className="hero-07-info__tags right-align-desktop">
                  <span className="tag tag-default tag-outline-medium">
                    Interactive design
                  </span>
                  <span className="tag tag-default tag-outline-medium">
                    Editorial
                  </span>
                  <span className="tag tag-default tag-outline-medium">
                    Web experience
                  </span>
                  <span className="tag tag-default tag-outline-medium">
                    UI/UX
                  </span>
                  <span className="tag tag-default tag-outline-medium">
                    Branding
                  </span>
                  <span className="tag tag-default tag-outline-medium">
                    Web design
                  </span>
                </div>
              </div>
            </div>
          </div>
          {/* scroll for more */}
          <div className="mxd-hero-07__more loading__fade">
            <AnimatedButton
              text="Scroll for more"
              as={"a"}
              className="btn btn-line-default btn-anim slide-down"
              href="#projects"
            >
              <i className="ph-bold ph-arrow-elbow-right-down" />
            </AnimatedButton>
          </div>
          {/*  */}
          <div className="mxd-hero-07__tl-trigger" />
        </div>
      </div>
    </div>
  );
}
