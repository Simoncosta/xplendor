"use client";
import Image from "next/image";
import { useEffect, useRef } from "react";
import { gsap } from "gsap";
import { ScrollTrigger } from "gsap/ScrollTrigger";

import VideoModalButton from "@/components/common/VideoModalButton";

export default function Hero() {
  const fadeOutRefs = useRef<Array<HTMLDivElement | null>>([]);
  const scaleOutRefs = useRef<Array<HTMLDivElement | null>>([]);

  useEffect(() => {
    // Fade Out Animation
    fadeOutRefs.current.forEach((el) => {
      if (el) {
        gsap
          .timeline({
            scrollTrigger: {
              trigger: ".mxd-hero-08__tl-trigger",
              start: "top 80%",
              end: "top 40%",
              scrub: true,
            },
          })
          .fromTo(
            el,
            { transform: "translate3d(0, 0, 0)", opacity: 1 },
            { transform: "translate3d(0, -5rem, 0)", opacity: 0 }
          );
      }
    });

    // Scale Out Animation
    scaleOutRefs.current.forEach((el) => {
      if (el) {
        gsap
          .timeline({
            scrollTrigger: {
              trigger: ".mxd-hero-08__tl-trigger",
              start: "top 40%",
              end: "top 10%",

              scrub: true,
            },
          })
          .fromTo(
            el,
            { transform: "translate3d(0, 0, 0)", scaleY: 1, opacity: 1 },
            { transform: "translate3d(0, -5rem, 0)", scaleY: 1.2, opacity: 0 }
          );
      }
    });

    // Cleanup
    return () => {
      ScrollTrigger.getAll().forEach((trigger) => trigger.kill());
    };
  }, []);
  return (
    <>
      <div className="mxd-section">
        <div className="mxd-hero-08">
          <div className="mxd-hero-08__wrap loading-wrap">
            <div className="mxd-hero-08__center">
              <div className="mxd-hero-08__headline">
                <div className="mxd-container">
                  <div className="hero-08-headline__image">
                    <Image
                      className="mxd-rotate-slow"
                      alt="Hero Image"
                      src="/img/hero/16_hero-img.webp"
                      width={800}
                      height={781}
                    />
                  </div>
                  <div
                    className="hero-08-headline__title hero-08-slide-out-scroll loading__item"
                    ref={(el) => {
                      fadeOutRefs.current[0] = el;
                    }}
                  >
                    <h1>
                      Creative visual designer
                      <br />
                      based in Odesa
                    </h1>
                  </div>
                </div>
              </div>
            </div>
            <div className="mxd-hero-08__bottom">
              <div
                className="mxd-hero-08__data-wrap hero-08-scale-out-scroll"
                ref={(el) => {
                  scaleOutRefs.current[1] = el;
                }}
              >
                <div className="mxd-hero__mark loading__item">
                  <span className="mark-icon" />
                  <span className="mark-text">Available for freelance</span>
                </div>
                <div className="mxd-hero-08__descr loading__item">
                  <p className="t-large t-bright t-medium t-120">
                    Award-winning creative projects, over ten years of digital
                    experience.
                  </p>
                </div>
              </div>
              <div
                className="mxd-hero-08__video-wrap hero-08-scale-out-scroll loading__item"
                ref={(el) => {
                  scaleOutRefs.current[0] = el;
                }}
              >
                <video
                  className="mxd-hero-08__video"
                  preload="auto"
                  autoPlay
                  loop
                  muted
                  poster="video/540x310_video-01.webp"
                >
                  <source type="video/mp4" src="video/540x310_video-01.mp4" />
                  <source type="video/webm" src="video/540x310_video-01.webm" />
                  <source type="video/ogv" src="video/540x310_video-01.ogv" />
                </video>
                <div className="mxd-hero-08__video-btn">
                  <VideoModalButton
                    videoSrc="https://vimeo.com/65036292"
                    iconClassName="ph-fill ph-play"
                    buttonClassName="btn btn-round btn-round-medium btn-accent slide-right anim-no-delay showreel-trigger"
                  />
                </div>
              </div>
            </div>
            <div className="mxd-hero-08__tl-trigger" />
          </div>
        </div>
      </div>
    </>
  );
}
