import React from "react";

import BlogCategories from "./BlogCategories";
import FeaturedItems from "./FeaturedItems";
import NewsLetter from "./NewsLetter";
import PopulerTags from "./PopulerTags";
import CommentForm from "./CommentForm";
import BlogSearch from "./BlogSearch";
export default function BlogDetails({ blogItem }) {
  return (
    <section className="tf-section3 flat-blog-detail flat-property-detail">
      <div className="container">
        <div className="row">
          <div className="col-lg-8">
            <div className="post">
              <h1 className="title-heading">{blogItem.title}</h1>
              <div className="icon-boxs flex flex-wrap gap-20">
                <div className="icon flex align-center">
                  <svg
                    width={16}
                    height={16}
                    viewBox="0 0 16 16"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                  >
                    <path
                      d="M11.9883 12.4831C11.5225 11.8664 10.9198 11.3662 10.2278 11.022C9.53575 10.6779 8.77324 10.4991 8.00033 10.4998C7.22743 10.4991 6.46492 10.6779 5.77288 11.022C5.08084 11.3662 4.47816 11.8664 4.01233 12.4831M11.9883 12.4831C12.8973 11.6746 13.5384 10.6088 13.8278 9.4272C14.1172 8.24555 14.0405 7.00386 13.608 5.86679C13.1754 4.72972 12.4075 3.75099 11.4059 3.0604C10.4044 2.36982 9.21656 2 8 2C6.78344 2 5.59562 2.36982 4.59407 3.0604C3.59252 3.75099 2.82455 4.72972 2.39202 5.86679C1.95949 7.00386 1.88284 8.24555 2.17221 9.4272C2.46159 10.6088 3.10333 11.6746 4.01233 12.4831M11.9883 12.4831C10.891 13.4619 9.47075 14.0019 8.00033 13.9998C6.52969 14.0021 5.10983 13.4621 4.01233 12.4831M10.0003 6.4998C10.0003 7.03024 9.78962 7.53894 9.41455 7.91402C9.03948 8.28909 8.53077 8.4998 8.00033 8.4998C7.4699 8.4998 6.96119 8.28909 6.58612 7.91402C6.21105 7.53894 6.00033 7.03024 6.00033 6.4998C6.00033 5.96937 6.21105 5.46066 6.58612 5.08559C6.96119 4.71052 7.4699 4.4998 8.00033 4.4998C8.53077 4.4998 9.03948 4.71052 9.41455 5.08559C9.78962 5.46066 10.0003 5.96937 10.0003 6.4998Z"
                      stroke="currentColor"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    />
                  </svg>
                  <span className="fw-6 text-color-3 fs-16">
                    Kathryn Murphy
                  </span>
                </div>
                <div className="icon flex align-center">
                  <svg
                    width={16}
                    height={16}
                    viewBox="0 0 16 16"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                  >
                    <path
                      d="M1.5 8.5V8C1.5 7.60218 1.65804 7.22064 1.93934 6.93934C2.22064 6.65804 2.60218 6.5 3 6.5H13C13.3978 6.5 13.7794 6.65804 14.0607 6.93934C14.342 7.22064 14.5 7.60218 14.5 8V8.5M8.70667 4.20667L7.29333 2.79333C7.20048 2.70037 7.09022 2.62661 6.96886 2.57628C6.84749 2.52595 6.71739 2.50003 6.586 2.5H3C2.60218 2.5 2.22064 2.65804 1.93934 2.93934C1.65804 3.22064 1.5 3.60218 1.5 4V12C1.5 12.3978 1.65804 12.7794 1.93934 13.0607C2.22064 13.342 2.60218 13.5 3 13.5H13C13.3978 13.5 13.7794 13.342 14.0607 13.0607C14.342 12.7794 14.5 12.3978 14.5 12V6C14.5 5.60218 14.342 5.22064 14.0607 4.93934C13.7794 4.65804 13.3978 4.5 13 4.5H9.414C9.14887 4.49977 8.89402 4.39426 8.70667 4.20667Z"
                      stroke="currentColor"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    />
                  </svg>
                  <span className="fw-6 text-color-3 fs-16">Furniture</span>
                </div>
                <div className="icon flex align-center">
                  <svg
                    width={16}
                    height={16}
                    viewBox="0 0 16 16"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                  >
                    <path
                      d="M5.75 6.5C5.75 6.56631 5.72366 6.62989 5.67678 6.67678C5.62989 6.72366 5.5663 6.75 5.5 6.75C5.4337 6.75 5.37011 6.72366 5.32322 6.67678C5.27634 6.62989 5.25 6.56631 5.25 6.5C5.25 6.4337 5.27634 6.37011 5.32322 6.32322C5.37011 6.27634 5.4337 6.25 5.5 6.25C5.5663 6.25 5.62989 6.27634 5.67678 6.32322C5.72366 6.37011 5.75 6.4337 5.75 6.5ZM5.75 6.5H5.5M8.25 6.5C8.25 6.56631 8.22366 6.62989 8.17678 6.67678C8.12989 6.72366 8.0663 6.75 8 6.75C7.9337 6.75 7.87011 6.72366 7.82322 6.67678C7.77634 6.62989 7.75 6.56631 7.75 6.5C7.75 6.4337 7.77634 6.37011 7.82322 6.32322C7.87011 6.27634 7.9337 6.25 8 6.25C8.0663 6.25 8.12989 6.27634 8.17678 6.32322C8.22366 6.37011 8.25 6.4337 8.25 6.5ZM8.25 6.5H8M10.75 6.5C10.75 6.56631 10.7237 6.62989 10.6768 6.67678C10.6299 6.72366 10.5663 6.75 10.5 6.75C10.4337 6.75 10.3701 6.72366 10.3232 6.67678C10.2763 6.62989 10.25 6.56631 10.25 6.5C10.25 6.4337 10.2763 6.37011 10.3232 6.32322C10.3701 6.27634 10.4337 6.25 10.5 6.25C10.5663 6.25 10.6299 6.27634 10.6768 6.32322C10.7237 6.37011 10.75 6.4337 10.75 6.5ZM10.75 6.5H10.5M1.5 8.50667C1.5 9.57333 2.24867 10.5027 3.30467 10.658C4.02933 10.7647 4.76133 10.8467 5.5 10.904V14L8.28933 11.2113C8.42744 11.0738 8.61312 10.9945 8.808 10.99C10.1091 10.958 11.407 10.8471 12.6947 10.658C13.7513 10.5027 14.5 9.574 14.5 8.506V4.494C14.5 3.426 13.7513 2.49733 12.6953 2.342C11.1406 2.11381 9.57135 1.99951 8 2C6.40533 2 4.83733 2.11667 3.30467 2.342C2.24867 2.49733 1.5 3.42667 1.5 4.494V8.506V8.50667Z"
                      stroke="currentColor"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    />
                  </svg>
                  <span className="fs-16">0 comment</span>
                </div>
                <div className="icon flex align-center">
                  <svg
                    width={16}
                    height={16}
                    viewBox="0 0 16 16"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                  >
                    <path
                      d="M4.5 2V3.5M11.5 2V3.5M2 12.5V5C2 4.60218 2.15804 4.22064 2.43934 3.93934C2.72064 3.65804 3.10218 3.5 3.5 3.5H12.5C12.8978 3.5 13.2794 3.65804 13.5607 3.93934C13.842 4.22064 14 4.60218 14 5V12.5M2 12.5C2 12.8978 2.15804 13.2794 2.43934 13.5607C2.72064 13.842 3.10218 14 3.5 14H12.5C12.8978 14 13.2794 13.842 13.5607 13.5607C13.842 13.2794 14 12.8978 14 12.5M2 12.5V7.5C2 7.10218 2.15804 6.72064 2.43934 6.43934C2.72064 6.15804 3.10218 6 3.5 6H12.5C12.8978 6 13.2794 6.15804 13.5607 6.43934C13.842 6.72064 14 7.10218 14 7.5V12.5"
                      stroke="currentColor"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    />
                  </svg>
                  <span className="fs-16">April 6, 2024</span>
                </div>
              </div>
              <div className="texts-1 fs-16 fw-5 lh-22 text-color-2">
                {blogItem.title}
              </div>
              <div className="image">
                <img
                  alt="images"
                  src="/assets/images/blog/blog-details-1.jpg"
                  width={1260}
                  height={710}
                />
              </div>
              <h3>2024 BMW Skytop Concept</h3>
              <p className="texts-2">
                Much like last year's Touring Coupe unveiled at the Concorso
                d'Eleganza Villa d'Este, the Skytop is just a one-off. However,
                the German luxury brand might change its mind provided enough
                people will be willing to pay an obscene amount of money for the
                8 Series Convertible with a targa top. Speaking with Automotive
                News, BMW Group design boss Adrian van Hooydonk said a
                production version is being considered.
              </p>
              <p className="texts-2">
                He believes anywhere between 20 to 25 cars could be built.
                Pricing wasn't mentioned but Skytop would certainly command a
                massive premium over the 8 Series Convertible donor car, priced
                at $100,500. Since the concept had the most powerful V-8 ever
                used by BMW, it was likely based on the M8 Convertible,
                available from $148,800.
              </p>
              <div className="text-box">
                <p className="texts fs-16 font text-color-3">
                  “Lorem ipsum dolor sit amet, consectetur adipiscing elit.
                  Pellentesque dui dui, laoreet eget libero quis, maximus cursus
                  metus. Integer id nibh sit amet purus congue mollis. ”
                </p>
                <span className="font fs-16 text-color-3 fw-5">
                  said Mike Fratantoni, MBA’s chief economist.
                </span>
              </div>
              <div className="box-image">
                <div className="overflow-hidden br-16">
                  <img
                    alt="imag-blog"
                    src="/assets/images/blog/blog-details-2.jpg"
                    width={615}
                    height={410}
                  />
                </div>
                <div className="overflow-hidden br-16">
                  <img
                    alt="imag-blog"
                    src="/assets/images/blog/blog-details-3.jpg"
                    width={615}
                    height={410}
                  />
                </div>
              </div>
              <p className="texts-2">
                For lots of BMW enthusiasts, money isn't really an issue. The
                Munich-based marque reportedly charged roughly $750,000 for the
                3.0 CSL, which was essentially a rebodied M4 CSL with a manual
                gearbox and a bit more power. The regular CSL retailed for
                $139,900, therefore making the retro-flavored 3.0 CSL nearly
                five and a half times more expensive. The BMW Group is also
                likely encouraged to give Skytop the green light by the success
                Rolls-Royce has had with its coachbuilt cars, some of which were
                allegedly sold for as much as $30 million.
              </p>
              <p className="texts-2">
                Adrian van Hooydonk believes there is a market for Skytop based
                on the amount of money people are willing to pay these days for
                a Z8 from which the new concept takes inspiration. The gorgeous
                roadster was far less exclusive, with 5,703 cars built from 1998
                until 2003. The targa-topped 8 Series Convertible also takes
                after the 503 and brings back the signature "sharknose" design
                trait.
              </p>
              <p className="texts-2">
                The 2023 Touring Coupe we mentioned earlier was also considered
                for production at some point. Initially, the plan was to build
                just nine but BMW brand design chief Domagoj Dukec wanted as
                many as 1,000 units. Doing so would've been simpler to
                homologate the Z4 M40i-based coupe that brought back the Clown
                Shoe. However, it ultimately never happened as BMW pulled the
                plug on the project.
              </p>
              <p className="texts-2">
                BMW has a long history of debuting beautiful concepts at the
                Concorso d'Eleganza Villa d’Este. Unfortunately, none of them
                saw the light of production day. The 2008 M1 Hommage springs to
                mind, but there were others, including the 2015 3.0 CSL Hommage,
                the 2011 328 Hommage, and the 2013 Gran Lusso Coupe penned by
                Pininfarina. Hopefully, the Skytop will break with tradition and
                enter production, even if it'll be limited.
              </p>
              <div className="tag-wrap flex flex-wrap justify-space align-center gap-8">
                <div className="tags-box">
                  <div className="tags flex-three">
                    <p className="text-color-2 fw-5 font">Tags :</p>
                    <div className="flex fs-13 fw-6 link-style-1">
                      <a href="#">AutoDecar</a>
                      <a href="#">BMW</a>
                    </div>
                  </div>
                </div>
                <div className="share-box flex-three">
                  <p className="text-color-2 fw-5 font">Share this post:</p>
                  <div className="icon-social">
                    <a href="#">
                      <i className="icon-autodeal-facebook" />
                    </a>
                    <a href="#">
                      <i className="icon-autodeal-linkedin" />
                    </a>
                    <a href="#">
                      <i className="icon-autodeal-twitter" />
                    </a>
                    <a href="#">
                      <i className="icon-autodeal-instagram" />
                    </a>
                  </div>
                </div>
              </div>
              <div className="wrap-review pd-0">
                <div className="box-title titles">
                  <h2>Comment (4)</h2>
                </div>
                <div className="comment-list">
                  <ol className="">
                    <li>
                      <div className="comment-list-wrap flex-three">
                        <div className="images flex-none">
                          <img
                            alt="images"
                            src="/assets/images/author/avt-cm1.jpg"
                            width={120}
                            height={120}
                          />
                        </div>
                        <div className="content">
                          <h5>Cameron Williamson</h5>
                          <p className="fs-12 lh-16">August 13, 2023</p>
                        </div>
                      </div>
                      <p className="texts text-color-2">
                        Lorem ipsum dolor sit amet, consectetur adipiscing elit.
                        Vestibulum aliquam eu nibh condimentum pharetra. Ut ut
                        interdum felis, sit amet accumsan nulla.&nbsp;
                      </p>
                      <div className="flex-three">
                        <p className="fs-12">Is this review helpful?</p>
                        <div className="helpful">
                          <a href="#" className="fs-12 fw-4 font-2">
                            Yes
                          </a>{" "}
                          <a href="#" className="fs-12 fw-4 font-2">
                            No
                          </a>
                        </div>
                      </div>
                    </li>
                    <li>
                      <div className="comment-list-wrap flex-three">
                        <div className="images flex-none">
                          <img
                            alt="images"
                            src="/assets/images/author/avt-cm2.jpg"
                            width={120}
                            height={120}
                          />
                        </div>
                        <div className="content">
                          <h5>Dianne Russell</h5>
                          <p className="fs-12 lh-16">August 13, 2023</p>
                        </div>
                      </div>
                      <p className="texts text-color-2">
                        Ut posuere sem eu venenatis auctor. Nam in ipsum lectus.
                        Mauris turpis quam, fermentum at mi varius, dapibus
                        rhoncus urna. Vestibulum ante ipsum primis in faucibus
                        orci luctus et ultrices posuere cubilia curae
                      </p>
                      <div className="flex-three">
                        <p className="fs-12">Is this review helpful?</p>
                        <div className="helpful">
                          <a href="#" className="fs-12 fw-4 font-2">
                            Yes
                          </a>{" "}
                          <a href="#" className="fs-12 fw-4 font-2">
                            No
                          </a>
                        </div>
                      </div>
                    </li>
                    <li>
                      <div className="comment-list-wrap flex-three">
                        <div className="images flex-none">
                          <img
                            alt="images"
                            src="/assets/images/author/avt-cm3.jpg"
                            width={120}
                            height={120}
                          />
                        </div>
                        <div className="content">
                          <h5>Annette Black</h5>
                          <p className="fs-12 lh-16">August 13, 2023</p>
                        </div>
                      </div>
                      <p className="texts text-color-2">
                        Sed molestie nibh vitae nisl pulvinar elementum.
                        Phasellus sit amet sapien vestibulum, gravida augue ac,
                        interdum nisl.
                      </p>
                      <div className="flex-three">
                        <p className="fs-12">Is this review helpful?</p>
                        <div className="helpful">
                          <a href="#" className="fs-12 fw-4 font-2">
                            Yes
                          </a>{" "}
                          <a href="#" className="fs-12 fw-4 font-2">
                            No
                          </a>
                        </div>
                      </div>
                    </li>
                  </ol>
                </div>
              </div>
              <div className="wrap-contact wrap-form pd-0">
                <div className="title">
                  <h2>Leave a Reply</h2>
                  <p>Your email address will not be published</p>
                </div>
                <CommentForm />
              </div>
            </div>
          </div>
          <div className="col-lg-4">
            <aside className="side-bar side-bar-1 side-blog">
              <div className="inner-side-bar">
                <div className="widget-rent">
                  <div className="flat-tabs style2">
                    <div className="form-s2">
                      <BlogSearch />
                      {/* End Job  Search Form*/}
                    </div>
                  </div>
                </div>
                <BlogCategories />
                <FeaturedItems />
                <NewsLetter />
                <PopulerTags />
              </div>
            </aside>
          </div>
        </div>
      </div>
    </section>
  );
}
