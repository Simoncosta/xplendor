import { carTypes } from "@/data/categories";
import { Pagination } from "swiper/modules";
import { Swiper, SwiperSlide } from "swiper/react";

export default function CarBrands() {
  const swiperOptions = {
    slidesPerView: 8,
    spaceBetween: 20,
    // autoplay: {
    //     delay: 0,
    //     disableOnInteraction: false,
    // },
    // speed: 10000,
    observer: true,
    observeParents: true,
    loop: true,
    pagination: {
      el: ".spd15",
      clickable: true,
    },
    breakpoints: {
      0: {
        slidesPerView: 1,
        spaceBetween: 20,
      },
      480: {
        slidesPerView: 2,
        spaceBetween: 20,
      },
      992: {
        slidesPerView: 5,
      },
      1440: {
        slidesPerView: 8,
      },
    },
  };
  return (
    <section className="section-car-list mt--5">
      <div className="container-fluid">
        <div className="row">
          <div className="col-lg-12">
            <Swiper
              {...swiperOptions}
              modules={[Pagination]}
              className="swiper partner-slide2 overflow-hidden"
            >
              {carTypes.map((carType, i) => (
                <SwiperSlide className="swiper-slide" key={i}>
                  <a href="#" className="partner-item style-1">
                    <div className="image">
                      <img
                        className="lazyload"
                        data-src={carType.imgSrc}
                        alt={carType.title}
                        src={carType.imgSrc}
                        width={carType.width}
                        height={carType.height}
                      />
                    </div>
                    <div className="content center">
                      <div className="fs-16 fw-6 title text-color-2 font-2">
                        {carType.title}
                      </div>
                      <span className="sub-title fs-12 fw-4 font-2">
                        {carType.count}
                      </span>
                    </div>
                  </a>
                </SwiperSlide>
              ))}

              <div className="swiper-pagination3 spd15 pb-1"></div>
            </Swiper>
          </div>
        </div>
      </div>
    </section>
  );
}
