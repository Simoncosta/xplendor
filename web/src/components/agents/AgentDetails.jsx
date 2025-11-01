import React from "react";

import { teamMembers } from "@/data/agents";
import Cars from "./Cars";
import Pagination2 from "../common/Pagination2";
export default function AgentDetails({ agentItem = teamMembers[0] }) {
  return (
    <section className="tf-section3 flat-property">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="inner-heading flex-two flex-wrap gap-20">
              <h1 className="heading-listing">Sale agents detail</h1>
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
          <div className="col-lg-12">
            <div className="sale-agents-detail flex mb-60">
              <div className="image">
                <img
                  className="lazyload"
                  alt="images"
                  src={agentItem.imageSrc}
                  width={450}
                  height={450}
                />
              </div>
              <div className="content">
                <h2>{agentItem.name}</h2>
                <div className="rating flex-three">
                  <p className="font">2.975 Reviews</p>
                  <div className="start">
                    <i className="icon-autodeal-star" />
                    <i className="icon-autodeal-star" />
                    <i className="icon-autodeal-star" />
                    <i className="icon-autodeal-star" />
                    <i className="icon-autodeal-star" />
                    <span>5/5</span>
                  </div>
                </div>
                <p>
                  Stay informed about emerging trends in the housing market,
                  such as the demand for sustainable homes, technological
                  advancements, and demographic shifts. Companies aligning with
                  these trends may present attractive investment opportunities.
                </p>
                <div className="ul contact">
                  <li className="flex-three">
                    <i className="icon-autodeal-phone2" />
                    <p className="fs-16 fw-5 lh-22 text-color-2 font">
                      +7-445-556-8337
                    </p>
                  </li>
                  <li className="flex-three">
                    <i className="icon-autodeal-mail" />
                    <p className="fs-16 fw-5 lh-22 text-color-2 font">
                      darlenerobertson@gmail.com
                    </p>
                  </li>
                </div>
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
                <a href="#" className="sc-button">
                  <span>Get quote</span>
                </a>
              </div>
            </div>
          </div>
          <Cars />
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
