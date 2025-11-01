import React from "react";

export default function Review() {
  return (
    <>
      <div className="widget-rating flex-three mb-30">
        <div className="icon-star">
          <i className="icon-autodeal-star" />
        </div>
        <div className="number">4.8</div>
        <div className="content">
          <p className="text-color-2">Overall Rating</p>
          <p className="text-color-2">
            Base on <span className="fw-6">372 Reviews</span>
          </p>
        </div>
      </div>
      <div className="flat-tabs mb-60">
        <div className="content-tab">
          <div className="content-inner tab-content">
            <div className="wrap-review pd-0">
              <div className="titles mb-5">
                <h4>372 Rating and Reviews</h4>
              </div>
              <div className="comment-list">
                <ol className="mb-30">
                  <li>
                    <div className="flex-one">
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
                          <h5>Leslie Alexander</h5>
                          <div className="icon-star text-color-3 fs-12">
                            <i className="icon-autodeal-star" />
                            <i className="icon-autodeal-star" />
                            <i className="icon-autodeal-star" />
                            <i className="icon-autodeal-star" />
                            <i className="icon-autodeal-star" />
                          </div>
                        </div>
                      </div>
                      <p className="fs-12 lh-16 font">August 13, 2023</p>
                    </div>
                    <p className="texts text-color-2">
                      It's really easy to use and it is exactly what I am
                      looking for. A lot of good looking templates &amp; it's
                      highly customizable. Live support is helpful, solved my
                      issue in no time.&nbsp;
                    </p>
                    <div className="image-wrap flex gap-20  review-images">
                      <img
                        className="lazyload"
                        data-src="/assets/images/car-list/car1.jpg"
                        alt=""
                        src="/assets/images/car-list/car1.jpg"
                        width={615}
                        height={462}
                        style={{ height: "auto" }}
                      />
                      <img
                        className="lazyload"
                        data-src="/assets/images/car-list/car2.jpg"
                        alt=""
                        src="/assets/images/car-list/car2.jpg"
                        width={615}
                        height={462}
                        style={{ height: "auto" }}
                      />
                      <img
                        className="lazyload"
                        data-src="/assets/images/car-list/car3.jpg"
                        alt=""
                        src="/assets/images/car-list/car3.jpg"
                        width={615}
                        height={462}
                        style={{ height: "auto" }}
                      />
                    </div>
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
                    <div className="flex-one">
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
                          <h5>Arlene McCoy</h5>
                          <div className="icon-star text-color-3 fs-12">
                            <i className="icon-autodeal-star" />
                            <i className="icon-autodeal-star" />
                            <i className="icon-autodeal-star" />
                            <i className="icon-autodeal-star" />
                            <i className="icon-autodeal-star" />
                          </div>
                        </div>
                      </div>
                      <p className="fs-12 lh-16 font">August 13, 2023</p>
                    </div>
                    <p className="texts text-color-2">
                      It's really easy to use and it is exactly what I am
                      looking for. A lot of good looking templates &amp; it's
                      highly customizable. Live support is helpful, solved my
                      issue in no time.&nbsp;
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
                    <div className="flex-one">
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
                          <h5>Jane Cooper</h5>
                          <div className="icon-star text-color-3 fs-12">
                            <i className="icon-autodeal-star" />
                            <i className="icon-autodeal-star" />
                            <i className="icon-autodeal-star" />
                            <i className="icon-autodeal-star" />
                            <i className="icon-autodeal-star" />
                          </div>
                        </div>
                      </div>
                      <p className="fs-12 lh-16 font">August 13, 2023</p>
                    </div>
                    <p className="texts text-color-2">
                      It's really easy to use and it is exactly what I am
                      looking for. A lot of good looking templates &amp; it's
                      highly customizable. Live support is helpful, solved my
                      issue in no time.&nbsp;
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
                <a href="#" className="fs-16 fw-5 font text-color-3 lh-22">
                  View more reviews
                  <i className="icon-autodeal-view-more" />
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div className="wrap-contact wrap-form pd-0">
        <div className="title">
          <h2>Leave a Reply</h2>
          <p>Your email address will not be published</p>
        </div>
        <div id="comments" className="comments">
          <div className="respond-comment">
            <form
              onSubmit={(e) => e.preventDefault()}
              id="contactform"
              className="comment-form form-submit"
              acceptCharset="utf-8"
            >
              <div className="inner-1 form-wg flex">
                <fieldset className="wg-box">
                  <label className="fw-6">Name</label>
                  <input
                    type="text"
                    className="my-input"
                    name="text"
                    placeholder="Your name"
                    required
                  />
                </fieldset>
                <fieldset className="wg-box">
                  <label className="fw-6">Email address</label>
                  <input
                    type="email"
                    className="my-input"
                    name="email"
                    placeholder="Your email"
                    required
                  />
                </fieldset>
              </div>
              <label className="flex checkbox-wrap">
                <input type="checkbox" />
                <span className="btn-checkbox flex-two" />
                <span className="font-2">
                  Save your name, email for the next time review
                </span>
              </label>
              <fieldset className="message-wrap">
                <label className="fw-6">Review</label>
                <textarea
                  id="comment-message"
                  name="message"
                  rows={4}
                  tabIndex={4}
                  placeholder="Your Message:"
                  aria-required="true"
                  defaultValue={""}
                />
              </fieldset>
              <button className="sc-button" name="submit" type="submit">
                <span>Post Comment</span>
              </button>
            </form>
          </div>
        </div>
      </div>
    </>
  );
}
