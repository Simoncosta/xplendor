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

export default function Testimonials() {
  return (
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
              modules={[Parallax, Pagination, Navigation, EffectFade, Autoplay]}
            >
              {/* additional required wrapper */}

              {testimonials.map((t: Testimonial, idx: number) => (
                <SwiperSlide
                  key={idx}
                  className="swiper-slide mxd-fullwidth-slide no-blur"
                >
                  <div className="mxd-testimonials-image">
                    <div className="container-fluid p-0 fullheight-l">
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
                              <h5 className="mxd-testimonials-image__name">
                                {t.author}
                              </h5>
                              <p className="mxd-testimonials-image__position t-small">
                                {t.position}{" "}
                                <a className="link-small-underline" href="#">
                                  {" "}
                                  {t.company}
                                </a>
                              </p>
                              <div className="mxd-testimonials-image__rating">
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
                            <p className="t-large">{t.text}</p>
                            <a
                              className="btn btn-anim btn-default btn-small btn-opposite slide-right-up"
                              href={t.projectLink}
                            >
                              <span className="btn-caption">Project Page</span>
                              <i className="ph ph-arrow-up-right" />
                            </a>
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
                    className="btn btn-round btn-round-small btn-outline slide-left anim-no-delay"
                    href="#"
                  >
                    <i className="ph ph-arrow-left" />
                  </a>
                </div>
                <div className="swiper-pagination mxd-swiper-pagination-fraction" />
                <div className="swiper-button-next mxd-slider-btn mxd-slider-btn-round-next">
                  <a
                    className="btn btn-round btn-round-small btn-outline slide-right anim-no-delay"
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
  );
}
