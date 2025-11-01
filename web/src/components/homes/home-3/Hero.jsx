import { slides2 } from "@/data/heroSlides";
import React from "react";

import { Autoplay, EffectFade, Navigation, Pagination } from "swiper/modules";
import { Swiper, SwiperSlide } from "swiper/react";

export default function Hero() {
  const swiperOptions = {
    autoplay: {
      delay: 6000,
      disableOnInteraction: false,
    },
    slidesPerView: 1,
    speed: 500,
    effect: "fade",
    fadeEffect: {
      crossFade: true,
    },
    navigation: {
      nextEl: ".snbn13",
      prevEl: ".snbp13",
    },
  };
  return (
    <Swiper
      modules={[Pagination, Navigation, Autoplay, EffectFade]}
      {...swiperOptions}
      className="swiper mainslider slider home3"
    >
      {slides2.map((slide, index) => (
        <SwiperSlide className="swiper-slide" key={index}>
          <div className="slider-item">
            <div className="img-slider">
              <img
                className="img-item lazyload"
                data-src={slide.imgSrc}
                alt=""
                src={slide.imgSrc}
                width={3840}
                height={1920}
              />
            </div>
            <div className="container relative">
              <div className="row">
                <div className="col-lg-12">
                  <div className="content po-content-two">
                    <div className="heading">
                      <span className="fs-20 fw-3 lh-26 text-color-1 fade-item fade-item-1">
                        {slide.heading}
                      </span>
                      <h1 className="text-color-1 fade-item fade-item-2">
                        {slide.subHeading}
                      </h1>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </SwiperSlide>
      ))}

      <div className="swiper-button-next snbn13" />
      <div className="swiper-button-prev snbp13" />
    </Swiper>
  );
}
