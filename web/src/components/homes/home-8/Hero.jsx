import { sliderSlides } from "@/data/heroSlides";
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
      nextEl: ".snbn22",
      prevEl: ".snbp22",
    },
  };
  return (
    <Swiper
      {...swiperOptions}
      modules={[Navigation, Pagination, Autoplay, EffectFade]}
      className="swiper mainslider slider home8"
    >
      {sliderSlides.map((slide, index) => (
        <SwiperSlide className="swiper-slide" key={index}>
          <div className="slider-item">
            <div className="container2 relative">
              <div className="row">
                <div className="col-lg-12">
                  <div className="content po-content-two">
                    <div className="heading center">
                      <div className="sub-title font fade-item fade-item-1">
                        {slide.subTitle}
                      </div>
                      <h1 className="fade-item fade-item-2">{slide.title}</h1>
                      <div className="img-slider2">
                        <img
                          className="img-item lazyload"
                          data-src={slide.imgSrc}
                          alt=""
                          src={slide.imgSrc}
                          width={slide.width}
                          height={slide.height}
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </SwiperSlide>
      ))}

      <div className="swiper-button-next snbn22" />
      <div className="swiper-button-prev snbp22" />
    </Swiper>
  );
}
