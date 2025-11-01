import { slides4 } from "@/data/heroSlides";
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
      nextEl: ".snbn21",
      prevEl: ".snbp21",
    },
  };
  return (
    <Swiper
      {...swiperOptions}
      modules={[Pagination, Navigation, EffectFade, Autoplay]}
      className="swiper mainslider slider home7"
    >
      {slides4.map((slide, index) => (
        <SwiperSlide key={index} className="swiper-slide">
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
            <div className="container2 relative">
              <div className="row">
                <div className="col-lg-12">
                  <div className="content flex justify-space">
                    <div className="po-content">
                      <div className="heading">
                        <h1 className="text-color-1 fade-item fade-item-1">
                          {slide.title}
                        </h1>
                        <p className="text-color-1 font fade-item fade-item-2">
                          {slide.description}
                        </p>
                        <div className="reserve-wrap fade-item fade-item-3">
                          <a
                            href={slide.reserveLink}
                            className="reserve text-color-1 fs-16 fw-5 font"
                          >
                            Reserve now
                          </a>
                        </div>
                      </div>
                      <div className="specifications-wrap style2">
                        {slide.specifications.map((spec, specIndex) => (
                          <div
                            key={specIndex}
                            className="specifications wow fadeInUp"
                            data-wow-delay={spec.delay}
                            data-wow-duration="1000ms"
                          >
                            <div className="specifications-title">
                              <div className="title fs-20 fw-5 lh-25 text-color-3">
                                {spec.title}
                              </div>
                            </div>
                            <div className="specifications-content">
                              <p className="text-color-1 font">{spec.value}</p>
                              <div className="font text-color-1">
                                {spec.description}
                              </div>
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                    <div className="controller-button">
                      {slide.controllers.map((controller, ctrlIndex) => (
                        <div
                          key={ctrlIndex}
                          className="flex-six controller wow fadeInRight"
                          data-wow-delay={controller.delay}
                          data-wow-duration="1000ms"
                        >
                          <div className="content-controller">
                            <p className="fs-16 fw-5 lh-20 text-color-1 right">
                              {controller.label1}
                            </p>
                            <p className="fs-16 fw-5 lh-20 text-color-1 right">
                              {controller.label2}
                            </p>
                          </div>
                          <div className="icon-controller">
                            <i className={controller.iconClass} />
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </SwiperSlide>
      ))}

      <div className="swiper-button-next style7-next snbn21" />
      <div className="swiper-button-prev style7-prev snbp21" />
    </Swiper>
  );
}
