import React from "react";

export default function Banner() {
  return (
    <section className="loan-calculator inner-2">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="heading center">
              <h2
                className="text-color-1 wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                Search car by body
              </h2>
              <p
                className="text-color-1 wow fadeInUpSmall"
                data-wow-delay="0.3s"
                data-wow-duration="1000ms"
              >
                Are you looking for your dream car? Or want to sell your old car
                for the best price? AutoDecar is the place for you!
              </p>
              <div
                className="btn-wrap wow fadeInUpSmall"
                data-wow-delay="0.4s"
                data-wow-duration="1000ms"
              >
                <a href="#" className="sc-button btn-svg">
                  <span>View all listing</span>
                  <i className="icon-autodeal-right2" />
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
