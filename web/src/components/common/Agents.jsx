import React from "react";
import { Link } from "react-router-dom";

import { teamMembers } from "@/data/agents";
export default function Agents({ parentClass = "tf-section" }) {
  return (
    <section className={parentClass}>
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="heading-section center">
              <h2
                className="wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                Meet Our Agents
              </h2>
            </div>
          </div>
          {teamMembers.slice(0, 4).map((elm, i) => (
            <div key={i} className="col-lg-3 col-6">
              <div className="tf-team box hover-img">
                <div className="images relative img-style">
                  <Link to={`/sale-agents-detail/${elm.id}`}>
                    <img
                      className="lazyloaded"
                      alt="images"
                      src={elm.imageSrc}
                      width={450}
                      height={450}
                    />
                  </Link>
                  <div className="icon-socials">
                    <a href="#">
                      <i className="icon-autodeal-facebook" />
                    </a>
                    <a href="#">
                      <i className="icon-autodeal-twitter" />
                    </a>
                    <a href="#">
                      <i className="icon-autodeal-linkedin" />
                    </a>
                    <a href="#">
                      <i className="icon-autodeal-instagram" />
                    </a>
                  </div>
                </div>
                <div className="content flex-two">
                  <div className="inner">
                    <h3 className="link-style-1">
                      <Link to={`/sale-agents-detail/${elm.id}`}>
                        {elm.name}
                      </Link>
                    </h3>
                    <p className="text-color-2">{elm.position}</p>
                  </div>
                  <div className="icon-box flex">
                    <a href="tel:0123456789">
                      <i className="fas fa-phone-alt" />
                    </a>
                    <a href="info:themes@gmail">
                      <i className="fas fa-envelope" />
                    </a>
                  </div>
                </div>
              </div>
            </div>
          ))}

          <div className="col-lg-12">
            <div className="box-text flex justify-center mt-15 flex-wrap center">
              <p>Become an agent and get the commission you deserve.</p>
              <Link to={`/contact`} className="text-color-3 font-2">
                Contact us
              </Link>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
