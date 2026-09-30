"use client";
import Link from "next/link";
import Image from "next/image";
import { useState } from "react";
import capabilities from "@/data/capabilities.json";
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
    <div className="mxd-section overflow-hidden padding-grid-pre-pinned">
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
                <div className="col-12 col-xl-3 mxd-grid-item no-margin"></div>
                <div className="col-12 col-xl-3 mxd-grid-item no-margin">
                  <div className="mxd-section-title__hrcontrols anim-uni-in-up">
                    <AnimatedButton
                      text="All Services"
                      className="btn btn-anim btn-default btn-outline slide-right-up"
                      href={`/services`}
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
                      onMouseMove={(e) => handleMouseMove(e, idx)}
                      onMouseLeave={handleMouseLeave}
                      key={idx}
                    >
                      <div className="mxd-cpb-list__divider anim-uni-in-up" />
                      <div
                        className="hover-reveal__content hover-reveal-280x340"
                        style={{
                          opacity: hoverState.activeIndex === idx ? 1 : 0,
                          transform: "translate(-80%, -50%)",
                          left: hoverState.x,

                          pointerEvents: "none",
                          transition: "opacity 0.3s ease",
                        }}
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
                          src={item.hoverImage}
                          width={600}
                          height={730}
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
