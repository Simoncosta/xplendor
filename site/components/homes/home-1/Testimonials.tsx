"use client";
import RevealText from "@/components/animation/RevealText";
import Image from "next/image";

import testimonialsData from "@/data/testimonials.json";
import { Swiper, SwiperSlide } from "swiper/react";
import { Navigation, Pagination, Parallax } from "swiper/modules";
import AnimatedButton from "@/components/animation/AnimatedButton";
import Link from "next/link";
export default function Testimonials() {
  return (
    <div className="mxd-section padding-grid-pre-mtext">
      <div className="mxd-container no-padding-container">
        {/* Block - Testimonials Slider #01 with Section Title Start */}
        <div className="mxd-block">
          <div className="container-fluid px-0">
            <div className="row gx-0">
              <div className="col-12 col-xl-5">
                {/* Additional Container Start */}
                <div className="mxd-container grid-container">
                  <div className="mxd-block">
                    {/* Section Title Start */}
                    <div className="mxd-section-title no-margin-desktop">
                      <div className="container-fluid p-0">
                        <div className="row g-0">
                          <div className="col-12 mxd-grid-item no-margin">
                            <div className="mxd-section-title__title anim-uni-in-up">
                              <RevealText as="h2" className="reveal-type">
                                What our clients say
                              </RevealText>
                            </div>
                          </div>
                          <div className="col-12 mxd-grid-item no-margin">
                            <div className="mxd-section-title__descr">
                              <p className="anim-uni-in-up">
                                We&apos;re more than just executors — we&apos;re
                                your creative partners. Don&apos;t just take our
                                word for it, see what clients have to say about
                                working with us.
                              </p>
                            </div>
                          </div>
                          <div className="col-12 mxd-grid-item no-margin">
                            <div className="mxd-section-title__controls anim-uni-in-up">
                              <a
                                className="mxd-section-title__image-link"
                                href="https://clutch.co/"
                                target="_blank"
                              >
                                <svg
                                  xmlns="http://www.w3.org/2000/svg"
                                  width={200}
                                  height={97}
                                  version="1.1"
                                  viewBox="0 0 200 97"
                                >
                                  <style
                                    dangerouslySetInnerHTML={{
                                      __html:
                                        "\n                                      .cls-1 {\n                                        fill: var(--t-bright);\n                                      }\n                                      .cls-2 {\n                                        fill: #e52421;\n                                      }\n                                    ",
                                    }}
                                  />
                                  <g>
                                    <path
                                      className="cls-1"
                                      d="M52.1,0h9.2v56.7h-9.2V0ZM93.1,56.7h9.2V18.3h-9.2v20.3h.2c0,8.5-7.3,9.3-9.3,9.3-5.3,0-6.5-5.2-6.5-8.3v-21.3h-9.2v21.6c0,5.3,1.8,9.6,4.5,12.8,2.5,2.5,6.5,4,11,4s7.3-.8,9.3-3.2v3.2h0ZM111.1,56.7h9.2v-29.3h6.7v-9.2h-6.7V4.7h-9.2v13.6h-6.8v9.2h6.8v29.3ZM148.4,56.6c5.2,0,10-1.8,13.7-5.2l1.3-1.3-6.3-6.3-1.3,1.7c-2,1.8-4.5,2.8-7.3,2.8-6.3,0-11-4.5-11-11.1s4.5-11,11-11,5.3.8,7.3,2.8l1.3,1.2,6-6-1.3-1.3c-3.8-3.2-8.3-5.2-13.4-5.2-11.3-.2-19.7,8-19.7,19.5,0,10.8,8.5,19.5,19.7,19.5ZM176.9,35.3c0-8.5,6.3-9.3,8.3-9.3,5.3,0,5.3,5.2,5.3,8.3v22.5h9.2v-22.6c.2-5-1-9.5-4-12.5-2.5-2.5-5.8-4-10-4s-6.5.8-9,3.2V0h-9.2v56.7h9.2v-21.5h.2ZM26.2,56.6c7.3,0,14.4-2.8,19-8l1.3-1.3-6-6.3-1.2,1.7c-3.3,3.3-8.3,5.3-13.2,5.3-10.3,0-17.7-8-17.7-18.8S15.9,10.3,26,10.3s9.3,2,13.2,5.3l1.3,1.3,6-6-1.3-1.3c-5-5.3-11.8-8-19-8C11.2,1.8,0,13.5,0,29.1s11.2,27.5,26.2,27.5ZM14.9,85.7h-3.3v3h-4.2l6.7-9.3h-3.7l-7.3,9.8v2.3h8.3v3.3h3.5v-3.3h2.5v-2.8h-2.5v-3ZM20.9,90.7c-.7,0-1.2.2-1.5.7-.3.3-.7.8-.7,1.5s.2,1.2.7,1.5c.3.3,1,.7,1.5.7s1.2-.2,1.5-.7c.3-.3.7-1,.7-1.5s-.2-1.2-.7-1.5c-.3-.5-.8-.7-1.5-.7ZM35.2,81c-1.2-1.3-3-2-5.2-2s-2,.2-3,.7c-.8.5-1.7,1-2.2,1.8s-.8,1.7-.8,2.7.2,1.8.7,2.5,1.2,1.3,2,1.7c.8.3,1.7.7,2.7.7,1.7,0,3-.5,3.8-1.3,0,1.5-.5,2.5-1.2,3.3-.8.8-1.8,1.2-3.2,1.2s-2.2-.3-2.8-.8l-1.3,2.7c.5.3,1.2.7,2,.8.8.2,1.5.3,2.3.3,1.5,0,3-.3,4.2-1,1.2-.7,2.2-1.7,2.8-2.8.7-1.3,1-2.8,1-4.5,0-2.7-.7-4.5-1.8-5.8ZM32.4,85.7c-.5.5-1.2.7-1.8.7s-1.5-.2-1.8-.7c-.5-.5-.7-1-.7-1.7s.2-1.3.7-1.7c.5-.3,1.2-.7,1.8-.7s1.5.2,2,.7.7,1,.7,1.7c-.2.7-.3,1.2-.8,1.7ZM44.1,76.2l-7.3,20.8h3.2l7.2-20.8h-3ZM52.4,85.2h-1.3l.2-3h6.5v-2.8h-9.5l-.8,8.7h4c1.3,0,2.3.2,2.8.5.5.3.8.8.8,1.5s-.3,1.2-.8,1.5c-.5.3-1.2.5-2.2.5s-1.7-.2-2.3-.3c-.8-.2-1.5-.7-2-1l-1.3,2.7c.7.5,1.7,1,2.7,1.2,1,.3,2.2.5,3.2.5s2.7-.2,3.5-.7c1-.5,1.7-1,2.2-1.8.5-.8.7-1.7.7-2.5,0-1.5-.5-2.7-1.5-3.5-1-1-2.5-1.3-4.7-1.3ZM62.2,90.7c-.7,0-1.2.2-1.5.7-.3.3-.7.8-.7,1.5s.2,1.2.7,1.5c.3.3,1,.7,1.5.7s1.2-.2,1.5-.7c.3-.3.7-1,.7-1.5s-.2-1.2-.7-1.5c-.3-.5-.8-.7-1.5-.7ZM75.8,80c-1-.7-2.2-1-3.5-1s-2.5.3-3.5,1-1.8,1.5-2.3,2.8c-.5,1.2-.8,2.7-.8,4.3s.3,3.2.8,4.3,1.3,2.2,2.3,2.8,2.2,1,3.5,1,2.5-.3,3.5-1,1.8-1.5,2.3-2.8c.5-1.2.8-2.7.8-4.3s-.3-3.2-.8-4.3c-.5-1.3-1.3-2.2-2.3-2.8ZM74.6,90.8c-.5.8-1.3,1.2-2.2,1.2s-1.7-.3-2.2-1.2c-.5-.8-.8-2-.8-3.8s.3-3,.8-3.8,1.3-1.2,2.2-1.2,1.7.3,2.2,1.2.8,2,.8,3.8c0,1.8-.3,3-.8,3.8Z"
                                    />
                                    <path
                                      className="cls-2"
                                      d="M141.3,37.1c0,3.7,3,6.5,6.5,6.5s6.5-3,6.5-6.5-3-6.5-6.5-6.5-6.5,2.8-6.5,6.5ZM108.6,82.5h-7c-.2,0-.5-.2-.5-.3l-2.2-6.7c-.2-.5-1-.5-1.2,0l-2.2,6.7c0,.2-.3.3-.5.3h-7c-.5,0-.8.7-.3,1l5.7,4.2c.2.2.3.3.2.7l-2.2,6.7c-.2.5.5,1,.8.7l5.7-4.2c.2-.2.5-.2.7,0l5.7,4.2c.5.3,1-.2.8-.7l-2.2-6.7c0-.2,0-.5.2-.7l5.7-4.2c.7-.3.5-1-.2-1ZM131.2,82.5h-7c-.2,0-.5-.2-.5-.3l-2.2-6.7c-.2-.5-1-.5-1.2,0l-2.2,6.7c0,.2-.3.3-.5.3h-7c-.5,0-.8.7-.3,1l5.7,4.2c.2.2.3.3.2.7l-2.2,6.7c-.2.5.5,1,.8.7l5.7-4.2c.2-.2.5-.2.7,0l5.7,4.2c.5.3,1-.2.8-.7l-2.2-6.7c0-.2,0-.5.2-.7l5.7-4.2c.5-.3.3-1-.2-1ZM154.2,82.5h-7c-.2,0-.5-.2-.5-.3l-2.2-6.7c-.2-.5-1-.5-1.2,0l-2.2,6.7c0,.2-.3.3-.5.3h-7c-.5,0-.8.7-.3,1l5.7,4.2c.2.2.3.3.2.7l-2.2,6.7c-.2.5.5,1,.8.7l5.7-4.2c.2-.2.5-.2.7,0l5.7,4.2c.5.3,1-.2.8-.7l-2.2-6.7c0-.2,0-.5.2-.7l5.7-4.2c.5-.3.3-1-.2-1ZM177.1,82.5h-7c-.2,0-.5-.2-.5-.3l-2.2-6.7c-.2-.5-1-.5-1.2,0l-2.2,6.7c0,.2-.3.3-.5.3h-7c-.5,0-.8.7-.3,1l5.7,4.2c.2.2.3.3.2.7l-2.2,6.7c-.2.5.5,1,.8.7l5.7-4.2c.2-.2.5-.2.7,0l5.7,4.2c.5.3,1-.2.8-.7l-2.2-6.7c0-.2,0-.5.2-.7l5.7-4.2c.5-.3.3-1-.2-1ZM199.8,83.5c.5-.3.2-1-.3-1h-7c-.2,0-.5-.2-.5-.3l-2.2-6.7c-.2-.5-1-.5-1.2,0l-2.2,6.7c0,.2-.3.3-.5.3h-7c-.5,0-.8.7-.3,1l5.7,4.2c.2.2.3.3.2.7l-2.2,6.7c-.2.5.5,1,.8.7l5.7-4.2c.2-.2.5-.2.7,0l5.7,4.2c.5.3,1-.2.8-.7l-2.2-6.7c0-.2,0-.5.2-.7l5.8-4.2Z"
                                    />
                                  </g>
                                </svg>
                              </a>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                    {/* Section Title Start */}
                  </div>
                </div>
                {/* Additional Container End */}
              </div>
              <div className="col-12 col-xl-7">
                {/* Testimonials Slider Start */}
                <div className="testimonials-slider overflow-hidden anim-uni-in-up">
                  {/* slider main container */}
                  <Swiper
                    className="swiper-testimonials"
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
                  >
                    {testimonialsData.map((item, idx) => (
                      <SwiperSlide className="swiper-slide" key={idx}>
                        <div className="mxd-testimonials-card bg-base-tint radius-m">
                          <div className="mxd-testimonials-card__content">
                            <div className="mxd-testimonials-card__media anim-uni-in-up">
                              <div className="mxd-testimonials-card__photo">
                                <Image
                                  alt="Author Photo"
                                  src={item.authorPhoto}
                                  width={item.authorPhotoWidth}
                                  height={item.authorPhotoHeight}
                                />
                              </div>
                              <div className="mxd-testimonials-card__company">
                                <Image
                                  alt="Company Logo"
                                  src={item.companyLogo}
                                  width={item.companyLogoWidth}
                                  height={item.companyLogoHeight}
                                />
                              </div>
                            </div>
                            <div className="mxd-testimonials-card__text">
                              <p className="anim-uni-in-up">{item.text}</p>
                              <AnimatedButton
                                text="Project Page"
                                className="btn btn-anim btn-default btn-small btn-outline slide-right-up anim-uni-in-up"
                                href={item.projectPage}
                              >
                                <i className="ph ph-arrow-up-right" />
                              </AnimatedButton>
                            </div>
                          </div>
                          <div className="mxd-testimonials-card__author">
                            <h5 className="mxd-testimonials-card__name anim-uni-in-up">
                              {item.authorName}
                            </h5>
                            <p className="mxd-testimonials-card__position t-small anim-uni-in-up">
                              {item.authorPosition}
                              <a className="" href={item.authorCompanyUrl}>
                                {item.authorCompany}
                              </a>
                            </p>
                          </div>
                        </div>
                      </SwiperSlide>
                    ))}

                    {/* navigation buttons */}
                    <div className="swiper-button-prev mxd-slider-btn mxd-slider-btn-round-prev animate-card-2">
                      <a
                        className="btn btn-round btn-round-small btn-outline slide-left anim-no-delay"
                        href="#"
                      >
                        <i className="ph ph-arrow-left" />
                      </a>
                    </div>
                    <div className="swiper-button-next mxd-slider-btn mxd-slider-btn-round-next animate-card-2">
                      <a
                        className="btn btn-round btn-round-small btn-outline slide-right anim-no-delay"
                        href="#"
                      >
                        <i className="ph ph-arrow-right" />
                      </a>
                    </div>
                  </Swiper>
                  <div className="testimonials-slider__shadow" />
                </div>
                {/* Testimonials Slider End */}
              </div>
            </div>
          </div>
        </div>
        {/* Block - Testimonials Slider #01 with Section Title Start */}
      </div>
    </div>
  );
}
