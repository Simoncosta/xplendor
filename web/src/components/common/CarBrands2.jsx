import { carBrands } from "@/data/categories";
import { Grid, Pagination } from "swiper/modules";
import { Swiper, SwiperSlide } from "swiper/react";

export default function CarBrands2() {
  const swiperOptions = {
    observeParents: true,
    spaceBetween: 30,
    slidesPerView: 6,

    // direction: 'horizontal',
    // loop: true,
    pagination: {
      el: ".spd6",
      clickable: true,
    },
    breakpoints: {
      0: {
        slidesPerView: 1,
        spaceBetween: 20,
      },
      450: {
        slidesPerView: 2,
        spaceBetween: 20,
      },
      768: {
        slidesPerView: 4,
      },
      1400: {
        slidesPerView: 6,
      },
    },
  };
  return (
    <section className="section-car-list2 tf-section3">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="partner-slide3-wrap">
              <Swiper
                {...swiperOptions}
                modules={[Pagination, Grid]}
                grid={{
                  rows: 2, // Equivalent to slidesPerColumn: 2
                  fill: "row", // Equivalent to slidesPerColumnFill: 'row'
                }}
                className="swiper partner-slide3 overflow-hidden"
              >
                {carBrands.map((slide, index) => (
                  <SwiperSlide className="swiper-slide" key={index}>
                    <a href="#" className="partner-item style-1">
                      <div className="image">
                        <img
                          className="lazyload"
                          data-src={slide.imgSrc}
                          alt="images"
                          src={slide.imgSrc}
                          width={slide.imgWidth}
                          height={slide.imgHeight}
                        />
                      </div>
                      <div className="content center">
                        <div className="fs-16 fw-6 title text-color-2 font-2">
                          {slide.title}
                        </div>
                        <span className="sub-title fs-12 fw-4 font-2">
                          {slide.subTitle}
                        </span>
                      </div>
                    </a>
                  </SwiperSlide>
                ))}

                <div className="swiper-pagination3 spd6 pb-1" />
              </Swiper>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
