import { testimonials } from "@/data/testimonials";
import { Navigation, Pagination } from "swiper/modules";
import { Swiper, SwiperSlide } from "swiper/react";

export default function Testimonials() {
  const swiperOptions = {
    // autoplay: {
    //     delay: 5000,
    //     disableOnInteraction: false,
    // },
    loop: true,
    slidesPerView: 1,
    spaceBetween: 30,
    centeredSlides: true,

    pagination: {
      el: ".spd18",
      clickable: true,
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
      992: {
        slidesPerView: 3,
        spaceBetween: 30,
      },
      1440: {
        slidesPerView: 4.3,
        spaceBetween: 30,
      },
    },
  };
  return (
    <section className="tf-section bg-1">
      <div className="container-fluid">
        <div className="row">
          <div className="col-lg-12">
            <div
              className="heading-section center wow fadeInUpSmall"
              data-wow-delay="0.2s"
              data-wow-duration="1000ms"
            >
              <h2>We love our clients</h2>
            </div>
          </div>
          <div className="col-lg-12">
            <Swiper
              {...swiperOptions}
              modules={[Navigation, Pagination]}
              className="swiper-container carousel-6 mask-0-3"
            >
              {testimonials.slice(0, 6).map((elm, i) => (
                <SwiperSlide key={i} className="swiper-slide">
                  <div className="tf-testimonial style-3 box-tes center">
                    <div className="inner-top">
                      <div className="icon">
                        <svg
                          width={36}
                          height={36}
                          viewBox="0 0 36 36"
                          fill="none"
                          xmlns="http://www.w3.org/2000/svg"
                        >
                          <path
                            d="M9.74992 4.5C14.5409 4.5 17.9999 8.4555 17.9999 14.313C17.9699 22.8225 11.5724 28.839 2.34292 29.994C1.48792 30.102 1.15792 28.914 1.94542 28.5645C5.48542 26.9895 7.27492 24.9915 7.50592 23.0145C7.67842 21.537 6.87592 20.2425 5.86642 19.9995C3.25192 19.371 1.49992 16.1145 1.49992 12.75C1.49992 10.562 2.36911 8.46354 3.91628 6.91637C5.46346 5.36919 7.56188 4.5 9.74992 4.5ZM27.7499 4.5C32.5409 4.5 35.9999 8.4555 35.9999 14.313C35.9699 22.8225 29.5724 28.839 20.3429 29.994C19.4879 30.102 19.1579 28.914 19.9454 28.5645C23.4854 26.9895 25.2749 24.9915 25.5059 23.0145C25.6784 21.537 24.8759 20.2425 23.8664 19.9995C21.2519 19.371 19.4999 16.1145 19.4999 12.75C19.4999 10.562 20.3691 8.46354 21.9163 6.91637C23.4635 5.36919 25.5619 4.5 27.7499 4.5Z"
                            fill="CurrentColor"
                          />
                        </svg>
                      </div>
                    </div>
                    <p className="fs-16 lh-22 text-color-2">"{elm.text}"</p>
                    <div className="author-box flex">
                      <div className="images">
                        <img
                          className="ls-is-cached lazyloaded"
                          alt="images"
                          src={elm.authorImage}
                          width={57}
                          height={57}
                        />
                      </div>
                      <div className="content">
                        <h5>{elm.authorName}</h5>
                        <p className="fs-12 lh-16">{elm.authorTitle}</p>
                      </div>
                    </div>
                  </div>
                </SwiperSlide>
              ))}

              <div className="swiper-pagination3 spd18 pb-1" />
            </Swiper>
          </div>
        </div>
      </div>
    </section>
  );
}
