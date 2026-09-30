"use client";
import Image from "next/image";

import testimonials from "@/data/testimonials-other-pages.json";
import { Testimonial } from "@/types/testimonials";
import { Swiper, SwiperSlide } from "swiper/react";
import {
  Autoplay,
  EffectFade,
  Navigation,
  Pagination,
  Parallax,
} from "swiper/modules";
import RevealText from "@/components/animation/RevealText";
import BackgroundParallax from "@/components/animation/BackgroundParallax";
import VelocityMarquee from "@/components/animation/VelocityMarquee";
import AnimatedButton from "@/components/animation/AnimatedButton";
export default function About() {
  return (
    <div className="mxd-wrapper mxd-wrapper-opposite padding-top-pre-title margin-bottom-pre-title anim-zoom-in-container">
      {/* Section - Culture & Value Start */}
      <div className="mxd-section padding-grid-pre-mtext">
        <div className="mxd-container grid-container">
          {/* Block - Section Title Start */}
          <div className="mxd-block">
            <div className="mxd-section-title">
              <div className="container-fluid p-0">
                <div className="row g-0">
                  <div className="col-12 col-xl-6 mxd-grid-item no-margin">
                    <div className="mxd-section-title__hrtitle">
                      <h2 className="opposite reveal-type anim-uni-in-up">
                        Our culture and
                        <br />
                        values
                      </h2>
                    </div>
                  </div>
                  <div className="col-12 col-xl-3 mxd-grid-item no-margin">
                    <div className="mxd-section-title__hrdescr">
                      <p className="t-opposite-medium anim-uni-in-up">
                        Imagination
                      </p>
                      <p className="t-opposite-medium anim-uni-in-up">
                        Strategy
                      </p>
                      <p className="t-opposite-medium anim-uni-in-up">Action</p>
                    </div>
                  </div>
                  <div className="col-12 col-xl-3 mxd-grid-item no-margin">
                    <div className="mxd-section-title__hrcontrols anim-uni-in-up">
                      <AnimatedButton
                        className="btn btn-anim btn-default btn-outline opposite slide-right-down"
                        as={"a"}
                        text="Testimonials"
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
          {/* Block - Images & Description Start */}
          <div className="mxd-block">
            <div className="mxd-values">
              <div className="container-fluid p-0">
                <div className="row g-0">
                  <div className="col-12 col-xl-6 mxd-values__item mxd-grid-item no-margin animate-card-2">
                    <BackgroundParallax className="mxd-values__image image-large-desktop image-values-1 parallax-img-small" />
                  </div>
                  <div className="col-12 col-xl-6 mxd-values__item mobile-reverse mxd-grid-item no-margin animate-card-2">
                    <BackgroundParallax className="mxd-values__image image-small-desktop image-values-2 parallax-img-small" />
                    <div className="mxd-values__descr anim-uni-in-up">
                      <RevealText
                        as="p"
                        className="t-bright opposite t-large reveal-type"
                      >
                        We thrive on collaboration, creativity, and innovation.
                        Our team values open communication, continuous learning,
                        and delivering results that make a real impact.
                      </RevealText>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          {/* Block - Images & Description Start */}
        </div>
      </div>
      {/* Section - Culture & Value End */}
      {/* Section - Marquee Text One Line Start */}
      <div className="mxd-section padding-mtext">
        <div className="mxd-container fullwidth-container">
          {/* Block - Marquee Text One Line Start */}
          <div className="mxd-block">
            <VelocityMarquee className="marquee marquee-right--gsap muted-extra">
              <div className="marquee__toright">
                {/* single item */}
                {Array.from({ length: 4 }).map((_, index) => (
                  <div
                    key={index}
                    className="marquee__item one-line item-regular text"
                  >
                    <p className="marquee__text">Clients Approve</p>
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
                ))}
              </div>
            </VelocityMarquee>
          </div>
          {/* Block - Marquee Text One Line End */}
        </div>
      </div>
      {/* Section - Marquee Text One Line End */}
      {/* Section - Testimonials Slider #03 Start */}
      <div
        id="testimonials"
        className="mxd-section overflow-hidden padding-default"
      >
        <div className="mxd-container grid-container">
          {/* Block - Testimonials Slider #03 Start */}
          <div className="mxd-block">
            <div className="testimonials-slider-2 no-padding">
              {/* slider main container */}
              <Swiper
                className="swiper-testimonials-2 no-padding"
                {...{
                  slidesPerView: 1,
                  grabCursor: true,
                  effect: "fade",
                  spaceBetween: 30,
                  autoplay: true,
                  delay: 3e3,
                  speed: 1e3,
                  loop: true,
                  parallax: true,

                  pagination: { el: ".swiper-pagination", type: "fraction" },
                  navigation: {
                    nextEl: ".swiper-button-next",
                    prevEl: ".swiper-button-prev",
                  },
                }}
                modules={[
                  Parallax,
                  Pagination,
                  Navigation,
                  EffectFade,
                  Autoplay,
                ]}
              >
                {/* additional required wrapper */}

                {testimonials.map((t: Testimonial, idx: number) => (
                  <SwiperSlide
                    key={idx}
                    className="swiper-slide mxd-fullwidth-slide no-blur"
                  >
                    <div className="mxd-testimonials-image">
                      <div className="row g-0 d-flex align-items-stretch fullheight-l">
                        <div className="row g-0 d-flex align-items-stretch fullheight-l">
                          <div className="col-12 col-xl-7 mxd-testimonials-image__content mxd-grid-item no-margin">
                            <div
                              className="mxd-testimonials-image__author"
                              data-swiper-parallax={-50}
                              data-swiper-parallax-opacity={0}
                            >
                              <div className="mxd-testimonials-image__avatar">
                                <Image
                                  alt="Review Author"
                                  src={t.avatar}
                                  width={300}
                                  height={300}
                                />
                              </div>
                              <div className="mxd-testimonials-image__info">
                                <h5 className="mxd-testimonials-image__name opposite">
                                  {t.author}
                                </h5>{" "}
                                <p className="mxd-testimonials-image__position t-small t-opposite-medium">
                                  {t.position}{" "}
                                  <a className="link-small-underline" href="#">
                                    {" "}
                                    {t.company}
                                  </a>
                                </p>
                                <div className="mxd-testimonials-image__rating opposite">
                                  {Array.from({ length: t.rating }).map(
                                    (_, i) => (
                                      <i key={i} className="ph-fill ph-star" />
                                    )
                                  )}
                                </div>
                              </div>
                            </div>
                            <div
                              className="mxd-testimonials-image__text"
                              data-swiper-parallax={-100}
                              data-swiper-parallax-opacity={0}
                            >
                              <p className="t-large t-opposite">{t.text}</p>
                              <AnimatedButton
                                className="btn btn-anim btn-default btn-small btn-base slide-right-up"
                                as={"a"}
                                text="Project Page"
                                href={t.projectLink}
                              >
                                <i className="ph ph-arrow-up-right" />
                              </AnimatedButton>
                            </div>
                          </div>
                          <div
                            className="col-12 col-xl-5 mxd-testimonials-image__img mxd-grid-item no-margin"
                            data-swiper-parallax-x={-50}
                            data-swiper-parallax-opacity={0}
                          >
                            <Image
                              alt=""
                              src={t.illustration}
                              width={1400}
                              height={1400}
                            />
                          </div>
                        </div>
                      </div>
                    </div>
                  </SwiperSlide>
                ))}

                {/* navigation buttons */}
                <div className="swiper-testimonials__controls">
                  <div className="swiper-button-prev mxd-slider-btn mxd-slider-btn-round-prev">
                    <a
                      className="btn btn-round btn-round-small btn-outline opposite slide-left anim-no-delay"
                      href="#"
                    >
                      <i className="ph ph-arrow-left" />
                    </a>
                  </div>
                  <div className="swiper-pagination mxd-swiper-pagination-fraction opposite" />
                  <div className="swiper-button-next mxd-slider-btn mxd-slider-btn-round-next">
                    <a
                      className="btn btn-round btn-round-small btn-outline opposite slide-right anim-no-delay"
                      href="#"
                    >
                      <i className="ph ph-arrow-right" />
                    </a>
                  </div>
                </div>
              </Swiper>
            </div>
          </div>
          {/* Block - Testimonials Slider #03 End */}
        </div>
      </div>
      {/* Section - Testimonials Slider #03 End */}
    </div>
  );
}
