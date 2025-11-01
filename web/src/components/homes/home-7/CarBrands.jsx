import { carBrands2 } from "@/data/categories";
import { Navigation, Pagination } from "swiper/modules";
import { Swiper, SwiperSlide } from "swiper/react";

export default function CarBrands() {
  const swiperOptions = {
    slidesPerView: 3,
    spaceBetween: 40,
    centeredSlides: true,
    centeredSlidesBounds: true,

    navigation: {
      nextEl: ".snbn20",
      prevEl: ".snbp20",
    },
    breakpoints: {
      0: {
        slidesPerView: 1,
        spaceBetween: 20,
      },
      768: {
        slidesPerView: 2,
        spaceBetween: 20,
      },
      1200: {
        slidesPerView: 3,
      },
    },
  };
  return (
    <section className="tf-section2">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="heading-section flex align-center justify-space flex-wrap gap-20">
              <h2
                className="wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                Search car by body
              </h2>
              <a
                href="#"
                className="tf-btn-arrow wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                View all
                <i className="icon-autodeal-btn-right" />
              </a>
            </div>
          </div>
          <div className="col-lg-12">
            {/* Swiper */}
            <Swiper
              {...swiperOptions}
              modules={[Pagination, Navigation]}
              className="swiper listing-slide overflow-hidden"
              style={{ minHeight: "100%" }}
              slidesPerGroupAuto
              loop
            >
              {carBrands2.map((slide, index) => (
                <SwiperSlide className="swiper-slide" key={index}>
                  <div className="tf-listing-slide">
                    <div className="img-style">
                      <img
                        className="lazyload"
                        data-src={slide.imgSrc}
                        alt="image"
                        src={slide.imgSrc}
                        width={slide.width}
                        height={slide.height}
                      />
                    </div>
                    <div className="content-style center">
                      <div className="fs-16 fw-6 lh-22 text-color-2 font-2">
                        {slide.title}
                      </div>
                      <p className="fs-12">{slide.carCount}</p>
                    </div>
                  </div>
                </SwiperSlide>
              ))}

              <div className="swiper-button-prev style-2 snbn20" />
              <div className="swiper-button-next style-2 snbp20" />
            </Swiper>
          </div>
        </div>
      </div>
    </section>
  );
}
