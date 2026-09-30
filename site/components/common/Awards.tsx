"use client";
import Link from "next/link";
import Image from "next/image";
import { useState } from "react";
import awardsData from "@/data/awards.json";
import RevealText from "@/components/animation/RevealText";
import AnimatedButton from "@/components/animation/AnimatedButton";
type HoverState = {
  activeIndex: number | null;
  x: number;
};
export default function Awards() {
  const [hoverState, setHoverState] = useState<HoverState>({
    activeIndex: null,
    x: 0,
  });

  const handleMouseMove = (e: React.MouseEvent<HTMLElement>, index: number) => {
    setHoverState({
      activeIndex: index,
      x: e.clientX,
    });
  };

  const handleMouseLeave = () => {
    setHoverState((pre) => {
      return {
        activeIndex: null,
        x: pre.x,
      };
    });
  };

  return (
    <div className="mxd-section overflow-hidden padding-default mobile-title">
      <div className="mxd-container grid-container">
        {/* Block - Section Title Start */}
        <div className="mxd-block">
          <div className="mxd-section-title">
            <div className="container-fluid p-0">
              <div className="row g-0">
                <div className="col-12 col-xl-6 mxd-grid-item no-margin">
                  <div className="mxd-section-title__hrtitle anim-uni-in-up">
                    <RevealText as="h2" className="reveal-type">
                      Awards &amp;
                      <br />
                      publications
                    </RevealText>
                  </div>
                </div>
                <div className="col-12 col-xl-3 mxd-grid-item no-margin" />
                <div className="col-12 col-xl-3 mxd-grid-item no-margin">
                  <div className="mxd-section-title__hrcontrols pre-title anim-uni-in-up">
                    <AnimatedButton
                      text="View More"
                      className="btn btn-anim btn-default btn-outline slide-right-up"
                      href={`/about-us`}
                    >
                      <i className="ph-bold ph-arrow-up-right" />
                    </AnimatedButton>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        {/* Block - Section Title End */}
        {/* Block - Approach and Philosophy List Start */}
        <div className="mxd-block">
          <div className="mxd-awards-list hover-reveal">
            {awardsData.map((item, idx) => (
              <a
                className="mxd-awards-list__item hover-reveal__item"
                href={item.url}
                target="_blank"
                key={idx}
                onMouseMove={(e) => handleMouseMove(e, idx)}
                onMouseLeave={handleMouseLeave}
              >
                <div className="mxd-awards-list__border anim-uni-in-up" />
                <div
                  style={{
                    opacity: hoverState.activeIndex === idx ? 1 : 0,
                    transform: "translate(-80%, -50%)",
                    left: hoverState.x,

                    pointerEvents: "none",
                    transition: "opacity 0.3s ease",
                  }}
                  className="hover-reveal__content overflow-visible hover-reveal-260x260"
                >
                  <Image
                    className="hover-reveal__image"
                    style={{
                      transform:
                        hoverState.activeIndex === idx
                          ? "scale(1,1)"
                          : "scale(1,1.4)",
                      transition: "transform 0.3s ease",
                    }}
                    alt="Project Preview"
                    src={item.image}
                    width={item.imageWidth}
                    height={item.imageHeight}
                  />
                </div>
                <div className="mxd-awards-list__inner">
                  <div className="container-fluid px-0">
                    <div className="row gx-0">
                      <div className="col-12 col-xl-8 mxd-grid-item no-margin">
                        <div className="mxd-awards-list__title anim-uni-in-up">
                          <div className="mxd-awards-list__icon">
                            <i className="ph ph-arrow-right" />
                          </div>
                          <p>{item.title}</p>
                        </div>
                      </div>
                      <div className="col-6 col-md-6 col-xl-2 mxd-grid-item no-margin">
                        <div className="mxd-awards-list__tagslist">
                          <ul>
                            {item.tags.map((tag, tagIdx) => (
                              <li className="anim-uni-in-up" key={tagIdx}>
                                <p className="t-small">{tag}</p>
                              </li>
                            ))}
                          </ul>
                        </div>
                      </div>
                      <div className="col-6 col-md-6 col-xl-2 mxd-grid-item no-margin">
                        <div className="mxd-awards-list__date anim-uni-in-up">
                          <p className="t-small">{item.date}</p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div className="mxd-awards-list__border anim-uni-in-up" />
              </a>
            ))}
          </div>
        </div>
        {/* Block - Approach and Philosophy List Start */}
      </div>
    </div>
  );
}
