import { carListings } from "@/data/cars";
import { Link } from "react-router-dom";
import { Pagination } from "swiper/modules";
import { Swiper, SwiperSlide } from "swiper/react";

import { useEffect, useState } from "react";
const priceRanges = [
  { title: "Auto Decar used cars", isActive: true, range: null },
  {
    title: "$50.000 - $80.000",
    isActive: false,
    range: { min: 50000, max: 80000 },
  },
  {
    title: "$80.000 - $100.000",
    isActive: false,
    range: { min: 80000, max: 100000 },
  },
];
export default function CarSlider() {
  const [filtered, setFiltered] = useState(carListings);
  const [selectedRange, setSelectedRange] = useState(priceRanges[0]);

  useEffect(() => {
    if (selectedRange.range) {
      setFiltered(
        [...carListings].filter(
          (elm) =>
            elm.price >= selectedRange.range.min &&
            elm.price <= selectedRange.range.max
        )
      );
    } else {
      setFiltered([...carListings]);
    }
  }, [selectedRange]);

  return (
    <section className="tf-section">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="heading-section flex align-center justify-space flex-wrap gap-20">
              <h2
                className="wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                Used Cars by Budget
              </h2>
              <Link
                to={`/listing-grid`}
                className="tf-btn-arrow wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                View all
                <i className="icon-autodeal-btn-right" />
              </Link>
            </div>
          </div>
          <div className="col-lg-12">
            <div className="flat-tabs themesflat-tabs">
              <div className="box-tab center">
                <ul className="menu-tab tab-title style flex">
                  {priceRanges.map((item, index) => (
                    <li
                      key={index}
                      className={`item-title ${
                        item == selectedRange ? "active" : ""
                      }`}
                      onClick={() => setSelectedRange(item)}
                    >
                      <h5 className="inner">{item.title}</h5>
                    </li>
                  ))}
                </ul>
              </div>
              <div className="content-tab">
                <div className="content-inner tab-content">
                  <Swiper
                    className="swiper tf-sw-mobile"
                    slidesPerView={3} // Equivalent to data-preview={3}
                    spaceBetween={30} // Equivalent to data-space={15}
                    breakpoints={{
                      1024: {
                        slidesPerView: 3, // Equivalent to data-tablet={2}
                        spaceBetween: 30, // Equivalent to data-space-lg={30}
                      },
                      768: {
                        slidesPerView: 2, // Equivalent to data-mobile-sm={2}
                        spaceBetween: 15, // Equivalent to data-space-md={15}
                      },
                      0: {
                        slidesPerView: 1, // Equivalent to data-mobile={1}
                        spaceBetween: 15, // Equivalent to data-space={15}
                      },
                    }}
                  >
                    {filtered.map((elm, i) => (
                      <SwiperSlide key={i} className="swiper-slide">
                        <div className="box-car-list hv-one">
                          <div className="image-group relative">
                            <div className="top flex-two">
                              <ul className="d-flex gap-8">
                                {elm.featured ? (
                                  <li className="flag-tag success">Featured</li>
                                ) : (
                                  ""
                                )}
                                <li className="flag-tag style-1">
                                  <div className="icon">
                                    <svg
                                      width={16}
                                      height={13}
                                      viewBox="0 0 16 13"
                                      fill="none"
                                      xmlns="http://www.w3.org/2000/svg"
                                    >
                                      <path
                                        d="M1.5 9L4.93933 5.56067C5.07862 5.42138 5.24398 5.31089 5.42597 5.2355C5.60796 5.16012 5.80302 5.12132 6 5.12132C6.19698 5.12132 6.39204 5.16012 6.57403 5.2355C6.75602 5.31089 6.92138 5.42138 7.06067 5.56067L10.5 9M9.5 8L10.4393 7.06067C10.5786 6.92138 10.744 6.81089 10.926 6.7355C11.108 6.66012 11.303 6.62132 11.5 6.62132C11.697 6.62132 11.892 6.66012 12.074 6.7355C12.256 6.81089 12.4214 6.92138 12.5607 7.06067L14.5 9M2.5 11.5H13.5C13.7652 11.5 14.0196 11.3946 14.2071 11.2071C14.3946 11.0196 14.5 10.7652 14.5 10.5V2.5C14.5 2.23478 14.3946 1.98043 14.2071 1.79289C14.0196 1.60536 13.7652 1.5 13.5 1.5H2.5C2.23478 1.5 1.98043 1.60536 1.79289 1.79289C1.60536 1.98043 1.5 2.23478 1.5 2.5V10.5C1.5 10.7652 1.60536 11.0196 1.79289 11.2071C1.98043 11.3946 2.23478 11.5 2.5 11.5ZM9.5 4H9.50533V4.00533H9.5V4ZM9.75 4C9.75 4.0663 9.72366 4.12989 9.67678 4.17678C9.62989 4.22366 9.5663 4.25 9.5 4.25C9.4337 4.25 9.37011 4.22366 9.32322 4.17678C9.27634 4.12989 9.25 4.0663 9.25 4C9.25 3.9337 9.27634 3.87011 9.32322 3.82322C9.37011 3.77634 9.4337 3.75 9.5 3.75C9.5663 3.75 9.62989 3.77634 9.67678 3.82322C9.72366 3.87011 9.75 3.9337 9.75 4Z"
                                        stroke="white"
                                        strokeWidth="1.5"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                      />
                                    </svg>
                                  </div>
                                  6
                                </li>
                              </ul>
                              <div className="year flag-tag">{elm.year}</div>
                            </div>
                            <Swiper
                              modules={[Pagination]}
                              pagination={{ el: `.spd${i}`, clickable: true }}
                              className="swiper-container carousel-2 img-style"
                            >
                              {elm.images.map((elm2, i2) => (
                                <SwiperSlide key={i2} className="swiper-slide">
                                  <img
                                    className="lazyload"
                                    alt="image"
                                    src={elm2.src}
                                    width={615}
                                    height={462}
                                  />
                                </SwiperSlide>
                              ))}

                              <div className="pagi2">
                                <div
                                  className={` swiper-pagination2 spd${i}`}
                                />
                              </div>
                            </Swiper>
                          </div>
                          <div className="content">
                            <div className="text-address">
                              <p className="text-color-3 font">{elm.type}</p>
                            </div>
                            <h5 className="link-style-1">
                              <Link to={`/listing-detail-v2/${elm.id}`}>
                                {elm.title}
                              </Link>
                            </h5>
                            <div className="icon-box flex flex-wrap">
                              <div className="icons flex-three">
                                <i className="icon-autodeal-km1" />
                                <span>{elm.km.toLocaleString()} kms</span>
                              </div>
                              <div className="icons flex-three">
                                <i className="icon-autodeal-diesel" />
                                <span>{elm.fuelType}</span>
                              </div>
                              <div className="icons flex-three">
                                <i className="icon-autodeal-automatic" />
                                <span>{elm.transmission}</span>
                              </div>
                            </div>
                            <div className="money fs-20 fw-5 lh-25 text-color-3">
                              ${elm.price.toLocaleString()}
                            </div>
                            <div className="days-box flex justify-space align-center">
                              <div className="img-author">
                                <img
                                  className="lazyload"
                                  alt="image"
                                  src={elm.authorImage}
                                  width={120}
                                  height={120}
                                />
                                <span className="font text-color-2 fw-5">
                                  {elm.authorName}
                                </span>
                              </div>
                              <Link
                                to={`/listing-detail-v2/${elm.id}`}
                                className="view-car"
                              >
                                View car
                              </Link>
                            </div>
                          </div>
                        </div>
                      </SwiperSlide>
                    ))}

                    <div className="swiper-pagination3" />
                  </Swiper>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
