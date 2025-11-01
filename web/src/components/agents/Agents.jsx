import React from "react";
import { Link } from "react-router-dom";

import { teamMembers } from "@/data/agents";
import Pagination2 from "../common/Pagination2";
export default function Agents() {
  return (
    <section className="tf-section3 flat-property">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="inner-heading flex-two flex-wrap gap-20">
              <h1 className="heading-listing">Sale agents</h1>
              <div className="social-listing flex-six flex-wrap">
                <p>Share this page:</p>
                <div className="icon-social style1">
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
          </div>
          {teamMembers.map((member, index) => (
            <div key={index} className="col-lg-3 col-6">
              <div className="tf-team box hover-img">
                <div className="images relative img-style">
                  <Link to={`/sale-agents-detail/${member.id}`}>
                    <img
                      className="lazyloaded"
                      data-src={member.imageSrc}
                      alt="images"
                      src={member.imageSrc}
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
                      <Link to={`/sale-agents-detail/${member.id}`}>
                        {member.name}
                      </Link>
                    </h3>
                    <p className="text-color-2">{member.position}</p>
                  </div>
                  <div className="icon-box flex">
                    <a href={`tel:${member.phone}`}>
                      <i className="fas fa-phone-alt" />
                    </a>
                    <a href={`mailto:${member.email}`}>
                      <i className="fas fa-envelope" />
                    </a>
                  </div>
                </div>
              </div>
            </div>
          ))}
          <div className="col-lg-12">
            <div className="themesflat-pagination clearfix center">
              <ul>
                <Pagination2 />
              </ul>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
