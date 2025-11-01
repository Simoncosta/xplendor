import { slidesData } from "@/data/heroSlides";
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
      nextEl: ".snbn10",
      prevEl: ".snbp10",
    },
  };
  return (
    <Swiper
      {...swiperOptions}
      modules={[Pagination, Navigation, EffectFade, Autoplay]}
      className="swiper mainslider slider home10"
    >
      {slidesData.map((slide, index) => (
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
                      <h1 className="text-color-1 fade-item fade-item-1">
                        {slide.heading}
                      </h1>
                      <p className="text-color-1 fs-20 fw-3 lh-25 font fade-item fade-item-2">
                        {slide.paragraph.split("\n").map((text, i) => (
                          <React.Fragment key={i}>
                            {text}
                            <br />
                          </React.Fragment>
                        ))}
                      </p>
                      <div className="chat-wrap fade-item fade-item-3">
                        <a href={slide.linkHref} className="chat">
                          {slide.linkText}
                        </a>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </SwiperSlide>
      ))}

      <div className="swiper-button-next snbn10" />
      <div className="swiper-button-prev snbp10" />
    </Swiper>
  );
}
