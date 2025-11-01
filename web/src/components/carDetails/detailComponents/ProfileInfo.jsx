import React from "react";

export default function ProfileInfo() {
  return (
    <>
      <div className="prolile-info flex-three mb-30">
        <div className="image">
          <img
            className="lazyload"
            data-src="/assets/images/author/avt1.jpg"
            alt="image"
            src="/assets/images/author/avt1.jpg"
            width={450}
            height={450}
          />
        </div>
        <div className="content">
          <h4>Car Empire</h4>
          <div className="verified flex-three">
            <div className="icon">
              <svg
                width={14}
                height={15}
                viewBox="0 0 14 15"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
              >
                <path
                  d="M5 8.00024L6.5 9.50024L9 6.00024M7 1.30957C5.49049 2.74306 3.48018 3.52929 1.39867 3.50024C1.13389 4.30689 0.999317 5.15057 1 5.99957C1 9.72757 3.54934 12.8596 7 13.7482C10.4507 12.8602 13 9.72824 13 6.00024C13 5.1269 12.86 4.28624 12.6013 3.49957H12.5C10.3693 3.49957 8.43334 2.66757 7 1.30957Z"
                  stroke="CurrentColor"
                  strokeWidth="1.5"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
              </svg>
            </div>
            <span className="fs-12 fw-6 lh-16">Verified dealer</span>
          </div>
        </div>
      </div>
      <div className="profile-map mb-30">
        <div className="list-icon-pf gap-8 flex-three">
          <i className="far fa-map" />
          <p className="font-1">
            2972 Westheimer Rd. Santa Ana, Illinois 85486
          </p>
        </div>
        <div className="map">
          <iframe
            className="map-content"
            src="https://www.google.com/maps/embed?pb=!1m14!1m12!1m3!1d7302.453092836291!2d90.47477022812872!3d23.77494577893369!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!5e0!3m2!1svi!2s!4v1627293157601!5m2!1svi!2s"
            allowFullScreen=""
            loading="lazy"
          ></iframe>
        </div>
      </div>
      <div className="profile-contact">
        <h6>Contact dealer</h6>
        <div className="btn-contact flex-two">
          <a href="#" className="btn-pf bg-orange">
            <i className="icon-autodeal-phone2" />
            <span className="fs-16 fw-5 lh-20 font text-color-1">
              Call to seller
            </span>
          </a>
          <a href="#" className="btn-pf bg-green">
            <i className="icon-autodeal-chat" />
            <span className="fs-16 fw-5 lh-20 font text-color-1">Chat</span>
          </a>
        </div>
      </div>
    </>
  );
}
