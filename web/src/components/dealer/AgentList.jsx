import React from "react";

export default function AgentList() {
  return (
    <ul className="list-agent">
      <li>
        <div className="author">
          <img
            alt="images"
            src="/assets/images/dashboard/agent4.jpg"
            width={80}
            height={80}
          />
          <div className="content">
            <h5>Jacob Jones</h5>
            <div className="overall-rating-detail-star">
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={1}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={2}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={3}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={4}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={5}
              />
            </div>
            <p>Honda cars Pasig</p>
          </div>
        </div>
        <a href="#" className="btn-agent">
          Request a call back
        </a>
      </li>
      <li>
        <div className="author">
          <img
            alt="images"
            src="/assets/images/dashboard/agent3.jpg"
            width={80}
            height={80}
          />
          <div className="content">
            <h5>Cameron Williamson</h5>
            <div className="overall-rating-detail-star">
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={1}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={2}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={3}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={4}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={5}
              />
            </div>
            <p>Honda cars Pasig</p>
          </div>
        </div>
        <a href="#" className="btn-agent">
          Request a call back
        </a>
      </li>
      <li>
        <div className="author">
          <img
            alt="images"
            src="/assets/images/dashboard/agent2.jpg"
            width={80}
            height={80}
          />
          <div className="content">
            <h5>Ronald Richards</h5>
            <div className="overall-rating-detail-star">
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={1}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={2}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={3}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={4}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={5}
              />
            </div>
            <p>Honda cars Pasig</p>
          </div>
        </div>
        <a href="#" className="btn-agent">
          Request a call back
        </a>
      </li>
      <li>
        <div className="author">
          <img
            alt="images"
            src="/assets/images/dashboard/agent1.jpg"
            width={80}
            height={80}
          />
          <div className="content">
            <h5>Robert Fox</h5>
            <div className="overall-rating-detail-star">
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={1}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={2}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={3}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={4}
              />
              <i
                className="star disabled-click icon-autodeal-star"
                data-rating={5}
              />
            </div>
            <p>Honda cars Pasig</p>
          </div>
        </div>
        <a href="#" className="btn-agent">
          Request a call back
        </a>
      </li>
    </ul>
  );
}
