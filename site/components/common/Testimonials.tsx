"use client";
import Image from "next/image";

import testimonialsPortfolioData from "@/data/testimonials-portfolio.json";
import { Swiper, SwiperSlide } from "swiper/react";
import { Navigation, Pagination, Parallax } from "swiper/modules";
import AnimatedButton from "../animation/AnimatedButton";
import Link from "next/link";

export default function Testimonials() {
  return (
    <div
      id="testimonials"
      className="mxd-section overflow-hidden padding-default"
    >
      <div className="mxd-container grid-container">
        {/* Block - Testimonials Slider #02 with Section Title Start */}
        <div className="mxd-block">
          <div className="testimonials-slider no-padding">
            {/* slider main container */}
            <Swiper
              {...{
                slidesPerView: "auto",
                grabCursor: true,
                spaceBetween: 30,
                autoplay: true,
                delay: 3e3,
                speed: 1e3,
                loop: true,
                parallax: true,

                pagination: {
                  el: ".swiper-pagination",
                  type: "fraction",
                },
                navigation: {
                  nextEl: ".swiper-button-next",
                  prevEl: ".swiper-button-prev",
                },
              }}
              modules={[Navigation, Pagination, Parallax]}
              className="swiper-testimonials no-padding"
            >
              {/* additional required wrapper */}

              {testimonialsPortfolioData.map((item, idx) => (
                <SwiperSlide
                  className="swiper-slide mxd-fullwidth-slide"
                  key={idx}
                >
                  <div className="mxd-testimonials-photo">
                    <div className="container-fluid p-0 fullheight-l">
                      <div className="row g-0 d-flex align-items-stretch fullheight-l">
                        <div className="col-12 col-lg-6 mxd-testimonials-photo__content mxd-grid-item no-margin">
                          <div
                            className="mxd-testimonials-photo__author"
                            data-swiper-parallax-x={-250}
                            data-swiper-parallax-opacity={0}
                          >
                            <div className="mxd-testimonials-photo__avatar">
                              <Image
                                alt="Review Author"
                                src={item.authorPhoto}
                                width={item.authorPhotoWidth}
                                height={item.authorPhotoHeight}
                              />
                            </div>
                            <div className="mxd-testimonials-photo__info">
                              <h5 className="mxd-testimonials-photo__name">
                                {item.authorName}{" "}
                              </h5>{" "}
                              <p className="mxd-testimonials-photo__position t-small">
                                {item.authorPosition}{" "}
                                <a
                                  className="link-small-underline"
                                  href={item.authorCompanyUrl}
                                >
                                  {item.authorCompany}
                                </a>
                              </p>
                              <div className="mxd-testimonials-photo__rating">
                                {Array.from({ length: item.rating }).map(
                                  (_, i) => (
                                    <i className="ph-fill ph-star" key={i} />
                                  )
                                )}
                              </div>
                            </div>
                          </div>
                          <div
                            className="mxd-testimonials-photo__text"
                            data-swiper-parallax-x={-300}
                            data-swiper-parallax-opacity={0}
                          >
                            <p className="t-large">{item.text}</p>
                            <AnimatedButton
                              text="Project Page"
                              className="btn btn-anim btn-default btn-small btn-opposite slide-right-up"
                              href={item.projectPage}
                            >
                              <i className="ph ph-arrow-up-right" />
                            </AnimatedButton>
                          </div>
                        </div>
                        <div
                          className="col-12 col-lg-6 mxd-testimonials-photo__image mxd-grid-item no-margin fullheight-l"
                          data-swiper-parallax-x={-450}
                          data-swiper-parallax-opacity={0}
                        >
                          <div className="mxd-testimonials-photo__image-inner radius-l fullheight-l">
                            <Image
                              alt="Testimonials Image"
                              src={item.testimonialImage}
                              width={item.testimonialImageWidth}
                              height={item.testimonialImageHeight}
                            />
                          </div>
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
        {/* Block - Testimonials Slider #02 with Section Title Start */}
      </div>
    </div>
  );
}
