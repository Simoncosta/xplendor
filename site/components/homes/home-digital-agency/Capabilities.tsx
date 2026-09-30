"use client";
import Link from "next/link";
import Image from "next/image";
import { useState } from "react";
import capabilities from "@/data/capabilities-digital-agency.json";
import RevealText from "@/components/animation/RevealText";
import AnimatedButton from "@/components/animation/AnimatedButton";
import { compatibility } from "@/types/capabilities";

type HoverState = {
  activeIndex: number | null;
  x: number;
};
export default function Capabilities() {
  const [hoverState, setHoverState] = useState<HoverState>({
    activeIndex: null,
    x: 0,
  });

  const handleMouseMove = (
    e: React.MouseEvent<HTMLDivElement>,
    index: number
  ) => {
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
    <div className="mxd-section overflow-hidden padding-grid-pre-mtext">
      <div className="mxd-container grid-container">
        {/* Block - Section Title Start */}
        <div className="mxd-block">
          <div className="mxd-section-title">
            <div className="container-fluid p-0">
              <div className="row g-0">
                <div className="col-12 col-xl-6 mxd-grid-item no-margin">
                  <div className="mxd-section-title__hrtitle">
                    <RevealText as="h2" className="reveal-type">
                      Our capabilities
                    </RevealText>
                  </div>
                </div>
                <div className="col-12 col-xl-3 mxd-grid-item no-margin">
                  <div className="mxd-section-title__hrdescr">
                    <p className="anim-uni-in-up">Design</p>
                    <p className="anim-uni-in-up">Development</p>
                    <p className="anim-uni-in-up">Mastership</p>
                  </div>
                </div>
                <div className="col-12 col-xl-3 mxd-grid-item no-margin">
                  <div className="mxd-section-title__hrcontrols anim-uni-in-up">
                    <AnimatedButton
                      text="Works"
                      className="btn btn-anim btn-default btn-outline slide-right-up"
                      href={`/works-masonry`}
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
        {/* Block - Our Capabilities List Start */}
        <div className="mxd-block">
          <div className="container-fluid p-0">
            <div className="row g-0">
              <div className="col-12 mxd-grid-item no-margin">
                <div className="mxd-cpb-list">
                  {capabilities.map((item: compatibility, idx: number) => (
                    <div
                      className="mxd-cpb-list__item hover-reveal__item"
                      key={idx}
                      onMouseMove={(e) => handleMouseMove(e, idx)}
                      onMouseLeave={handleMouseLeave}
                    >
                      <div className="mxd-cpb-list__divider anim-uni-in-up" />
                      <div
                        style={{
                          opacity: hoverState.activeIndex === idx ? 1 : 0,
                          transform: "translate(-80%, -50%)",
                          left: hoverState.x,

                          pointerEvents: "none",
                          transition: "opacity 0.3s ease",
                        }}
                        className="hover-reveal__content hover-reveal-280x340"
                      >
                        <Image
                          className="hover-reveal__image"
                          alt="Project Preview"
                          src={item.hoverImage}
                          width={600}
                          height={730}
                          style={{
                            transform:
                              hoverState.activeIndex === idx
                                ? "scale(1,1)"
                                : "scale(1,1.4)",
                            transition: "transform 0.3s ease",
                          }}
                        />
                      </div>
                      <div className="mxd-cpb-list__content anim-uni-in-up">
                        <h6 className="mxd-cpb-list__title">{item.title}</h6>
                        <div className="mxd-cpb-list__num">
                          <span>{item.num}</span>
                        </div>
                      </div>
                      <div className="mxd-cpb-list__image anim-uni-in-up">
                        <Image
                          alt="Project Preview"
                          src={item.mainImage}
                          width={1200}
                          height={800}
                        />
                      </div>
                      <div className="mxd-cpb-list__divider anim-uni-in-up" />
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </div>
        {/* Block - Our Capabilities List Start */}
      </div>
    </div>
  );
}
