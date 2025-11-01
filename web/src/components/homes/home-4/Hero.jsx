import { sliderData } from "@/data/heroSlides";
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
      nextEl: ".snbn17",
      prevEl: ".snbp17",
    },
  };
  return (
    <Swiper
      {...swiperOptions}
      modules={[Autoplay, EffectFade, Pagination, Navigation]}
      className="swiper mainslider slider home4"
    >
      {sliderData.map((slide, index) => (
        <SwiperSlide className="swiper-slide" key={index}>
          <div className="slider-item">
            <div className="img-slider">
              <img
                className="img-item lazyload"
                data-src={slide.imgSrc}
                alt={slide.imgAlt}
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
                      <h1 className="text-color-1 fade-item fade-item-1">
                        {slide.heading}
                      </h1>
                      <p className="fs-20 fw-3 lh-25 text-color-1 font fade-item fade-item-2">
                        {slide.description.split("\n").map((text, i) => (
                          <React.Fragment key={i}>{text}</React.Fragment>
                        ))}
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </SwiperSlide>
      ))}

      <div className="swiper-button-next snbn17" />
      <div className="swiper-button-prev snbp17" />
    </Swiper>
  );
}
