"use client";
import Link from "next/link";
import Image from "next/image";
import { useState } from "react";
import RevealText from "../animation/RevealText";
import AnimatedButton from "../animation/AnimatedButton";
import { projects8 } from "@/data/projects.json";
type HoverState = {
  activeIndex: number | null;
  x: number;
};
export default function PortfolioList() {
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
    <div className="mxd-section overflow-hidden padding-grid-pre-mtext">
      <div className="mxd-container grid-container">
        {/* Block - Section Title Start */}
        <div className="mxd-block">
          <div className="mxd-section-title">
            <div className="container-fluid p-0">
              <div className="row g-0">
                <div className="col-12 col-xl-6 mxd-grid-item no-margin">
                  <div className="mxd-section-title__hrtitle">
                    <RevealText as="h2" className="reveal-type anim-uni-in-up">
                      Portfolio archive
                    </RevealText>
                  </div>
                </div>
                <div className="col-12 col-xl-2 mxd-grid-item no-margin" />
                <div className="col-12 col-xl-4 mxd-grid-item no-margin">
                  <div className="mxd-section-title__hrcontrols pre-title anim-uni-in-up">
                    <AnimatedButton
                      text="Clients Approve"
                      as={"a"}
                      className="btn btn-anim btn-default btn-outline slide-right-down"
                      href="#testimonials"
                    >
                      <i className="ph-bold ph-arrow-down-right" />
                    </AnimatedButton>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        {/* Block - Section Title End */}
        {/* Block - Projects List #01 Start */}
        <div className="mxd-block">
          <div className="mxd-projects-list hover-reveal">
            {projects8.map((item, idx) => (
              <Link
                key={item.id}
                className="mxd-projects-list__item hover-reveal__item"
                href={`/project-details`}
                onMouseMove={(e) => handleMouseMove(e, idx)}
                onMouseLeave={handleMouseLeave}
              >
                <div className="mxd-projects-list__border anim-uni-in-up" />
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
                    style={{
                      transform:
                        hoverState.activeIndex === idx
                          ? "scale(1,1)"
                          : "scale(1,1.4)",
                      transition: "transform 0.3s ease",
                    }}
                    className="hover-reveal__image"
                    alt="Project Preview"
                    src={item.thumb}
                    width={600}
                    height={730}
                  />
                </div>
                <div className="mxd-projects-list__inner">
                  <div className="container-fluid px-0">
                    <div className="row gx-0">
                      <div className="col-12 col-xl-8 mxd-grid-item no-margin">
                        <div className="mxd-projects-list__title anim-uni-in-up">
                          <div className="mxd-projects-list__icon">
                            <i className="ph ph-arrow-right" />
                          </div>
                          <p>{item.title}</p>
                        </div>
                        <div className="mxd-projects-list__image anim-uni-in-up">
                          <Image
                            alt="Project Preview"
                            src={item.image}
                            width={1200}
                            height={800}
                          />
                        </div>
                      </div>
                      <div className="col-6 col-md-6 col-xl-2 mxd-grid-item no-margin">
                        <div className="mxd-projects-list__tagslist">
                          <ul>
                            {item.tags.map((t, i) => (
                              <li key={i} className="anim-uni-in-up">
                                <p className="t-small">{t}</p>
                              </li>
                            ))}
                          </ul>
                        </div>
                      </div>
                      <div className="col-6 col-md-6 col-xl-2 mxd-grid-item no-margin">
                        <div className="mxd-projects-list__date anim-uni-in-up">
                          <p className="t-small">{item.date}</p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div className="mxd-projects-list__border anim-uni-in-up" />
              </Link>
            ))}
          </div>
        </div>
        {/* Block - Projects List #01 End */}
      </div>
    </div>
  );
}
