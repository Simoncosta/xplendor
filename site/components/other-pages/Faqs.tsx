"use client";
import Link from "next/link";
import Image from "next/image";
import { useEffect, useRef, useState } from "react";
import faqs from "@/data/faqs.json";
import { Faq } from "@/types/faq";
import AnimateRotation from "../animation/AnimateRotation";
import BackgroundParallax from "../animation/BackgroundParallax";

export default function Faqs() {
  const faqCotentRefs = useRef<(HTMLDivElement | null)[]>([]);
  const [faqContentHeights, setfaqContentHeights] = useState<number[]>([]);
  const [activeFaq, setActiveFaq] = useState(-1);
  useEffect(() => {
    // Get scrollHeight for each submenu and store in state
    const heights = faqCotentRefs.current.map((submenu) =>
      submenu ? submenu.scrollHeight : 0
    );
    setfaqContentHeights(heights);
  }, []);
  return (
    <>
      <div className="mxd-section mxd-section-inner-headline padding-s-text-pre-block">
        <div className="mxd-container grid-container">
          {/* Block - Inner Page Headline Start */}
          <div className="mxd-block loading-wrap">
            <div className="container-fluid px-0">
              <div className="row gx-0">
                {/* Inner Headline Name Start */}
                <div className="col-12 col-xl-2 mxd-grid-item no-margin">
                  <div className="mxd-block__name name-inner-headline loading__item">
                    <p className="mxd-point-subtitle">
                      <svg
                        version="1.1"
                        xmlns="http://www.w3.org/2000/svg"
                        x="0px"
                        y="0px"
                        width="20px"
                        height="20px"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                      >
                        <path
                          fill="currentColor"
                          d="M19.6,9.6c0,0-3,0-4,0c-0.4,0-1.8-0.2-1.8-0.2c-0.6-0.1-1.1-0.2-1.6-0.6c-0.5-0.3-0.9-0.8-1.2-1.2
                    c-0.3-0.4-0.4-0.9-0.5-1.4c0,0-0.1-1.1-0.2-1.5c-0.1-1.1,0-4.4,0-4.4C10.4,0.2,10.2,0,10,0S9.6,0.2,9.6,0.4c0,0,0.1,3.3,0,4.4
                    c0,0.4-0.2,1.5-0.2,1.5C9.4,6.7,9.2,7.2,9,7.6C8.7,8.1,8.2,8.5,7.8,8.9c-0.5,0.3-1,0.5-1.6,0.6c0,0-1.2,0.1-1.7,0.2
                    c-1,0.1-4.2,0-4.2,0C0.2,9.6,0,9.8,0,10c0,0.2,0.2,0.4,0.4,0.4c0,0,3.1-0.1,4.2,0c0.4,0,1.7,0.2,1.7,0.2c0.6,0.1,1.1,0.2,1.6,0.6
                    c0.4,0.3,0.8,0.7,1.1,1.1c0.3,0.5,0.5,1,0.6,1.6c0,0,0.1,1.3,0.2,1.7c0,1,0,4.1,0,4.1c0,0.2,0.2,0.4,0.4,0.4s0.4-0.2,0.4-0.4
                    c0,0,0-3.1,0-4.1c0-0.4,0.2-1.7,0.2-1.7c0.1-0.6,0.2-1.1,0.6-1.6c0.3-0.4,0.7-0.8,1.1-1.1c0.5-0.3,1-0.5,1.6-0.6
                    c0,0,1.3-0.1,1.8-0.2c1,0,4,0,4,0c0.2,0,0.4-0.2,0.4-0.4C20,9.8,19.8,9.6,19.6,9.6L19.6,9.6z"
                        />
                      </svg>
                      <span>FAQ</span>
                    </p>
                  </div>
                </div>
                {/* Inner Headline Name Start */}
                {/* Inner Headline Content Start */}
                <div className="col-12 col-xl-8 mxd-grid-item no-margin">
                  <div className="mxd-block__content">
                    <div className="mxd-block__inner-headline">
                      <h1 className="inner-headline__title headline-img-before headline-img-02 loading__item">
                        Everything you need to know
                      </h1>
                      <p className="inner-headline__text t-large t-bright loading__item">
                        Have questions? We&apos;ve got the answers! Here,
                        you&apos;ll find clear and concise information about our
                        services, process, and what to expect when working with
                        us. If you need more details, feel free to reach out!
                      </p>
                    </div>
                  </div>
                </div>
                {/* Inner Headline Content End */}
              </div>
            </div>
          </div>
          {/* Block - Inner Page Headline End */}
        </div>
      </div>

      {/* Section - Inner Page Headline Start */}
      <div className="mxd-section mxd-section-inner-form padding-default">
        <div className="mxd-container grid-container">
          {/* Block - Inner Page Headline Start */}
          <div className="mxd-block">
            <div className="container-fluid px-0">
              <div className="row gx-0">
                {/* Inner Headline Name Start */}
                <div className="col-12 col-xl-2 mxd-grid-item no-margin" />
                {/* Inner Headline Name Start */}
                {/* Inner Headline Content Start */}
                <div className="col-12 col-xl-8 mxd-grid-item no-margin">
                  <div className="mxd-block__content">
                    <div className="mxd-accordion loading__fade">
                      {faqs.map((f: Faq, idx: number) => (
                        <div key={idx} className="mxd-accordion__item">
                          <div className="mxd-accordion__divider anim-uni-in-up" />
                          <div
                            onClick={() =>
                              setActiveFaq((pre) => (pre == idx ? -1 : idx))
                            }
                            className="mxd-accordion__title anim-uni-in-up"
                          >
                            <h6>{f.question}</h6>
                            <div
                              className={`mxd-accordion__arrow ${
                                idx == activeFaq ? "accordion-rotate" : ""
                              } `}
                            >
                              <i className="ph ph-plus" />
                            </div>
                          </div>
                          <div
                            className="mxd-accordion__content"
                            style={{
                              display: "block",
                              height:
                                activeFaq === idx
                                  ? `calc(${faqContentHeights[idx]}px + 3.4rem)`
                                  : 0,
                              paddingBottom: activeFaq === idx ? "3.4rem" : 0,
                              transition: "all 0.3s ease",
                            }}
                            ref={(el) => {
                              faqCotentRefs.current[idx] = el;
                            }}
                          >
                            <p className="mxd-accordion__text">{f.answer}</p>
                          </div>
                          <div className="mxd-accordion__divider anim-uni-in-up" />
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
                {/* Inner Headline Content End */}
              </div>
            </div>
          </div>
          {/* Block - Inner Page Headline End */}
        </div>
      </div>
      {/* Section - Inner Page Headline End */}
      {/* Section - Parallax Divider Start */}
      <div className="mxd-section padding-pre-title">
        <div className="mxd-container">
          <div className="mxd-container__circle">
            <Link
              className="btn-rotating btn-rotating-blur-outline ver-02"
              href={`/contact`}
            >
              {/* SVG rotating text */}
              <AnimateRotation
                as="svg"
                version="1.1"
                id="scrollDown"
                xmlns="http://www.w3.org/2000/svg"
                xmlnsXlink="http://www.w3.org/1999/xlink"
                x="0px"
                y="0px"
                viewBox="0 0 160 160"
                enableBackground={"new 0 0 160 160"}
                xmlSpace="preserve"
                className="btn-rotating__text animate-rotation"
                value={360}
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
                      Let&apos;s start new project * Let&apos;s start new
                      project *
                    </textPath>
                  </text>
                </g>
              </AnimateRotation>
              {/* image */}
              <Image
                className="btn-rotating__image"
                alt="Object"
                src="/img/icons/300x300_obj-btn-02.webp"
                width={300}
                height={300}
              />
            </Link>
          </div>
          <div className="mxd-divider">
            <BackgroundParallax className="mxd-divider__image divider-image-1 parallax-img" />
          </div>
        </div>
      </div>
      {/* Section - Parallax Divider End */}
    </>
  );
}
