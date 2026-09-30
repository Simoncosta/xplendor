import Link from "next/link";
import Image from "next/image";

export default function Demos() {
  return (
    <div id="demo" className="mxd-section padding-grid-pre-mtext">
      <div className="mxd-container">
        {/* Block - Demo List Start */}
        <div className="mxd-block">
          <div className="mxd-demo-list">
            {/* items row */}
            <div className="mxd-demo-list__row">
              {/* single item */}
              <Link
                className="mxd-demo-list__item animate-card-2"
                href={`/home-main`}
                target="_blank"
              >
                <div className="mxd-demo-list__image">
                  <Image
                    alt="Rayo Demo Screen"
                    width={1000}
                    height={628}
                    src="/img/demo/screens/01.webp"
                  />
                  <div className="mxd-demo-list__screen screen-01" />
                </div>
                <div className="mxd-demo-list__caption">
                  <span className="mxd-demo-list__link">Main Home</span>
                  <span className="mxd-demo-list__num">/01</span>
                </div>
              </Link>
              {/* single item */}
              <Link
                className="mxd-demo-list__item item-accent animate-card-2"
                href={`/home-software-development-company`}
                target="_blank"
              >
                <div className="mxd-demo-list__image">
                  <Image
                    alt="Rayo Demo Screen"
                    width={1000}
                    height={628}
                    src="/img/demo/screens/02.webp"
                  />
                  <div className="mxd-demo-list__screen screen-02" />
                </div>
                <div className="mxd-demo-list__caption">
                  <span className="mxd-demo-list__link opposite">
                    Software Development Company
                  </span>
                  <span className="mxd-demo-list__num opposite">/02</span>
                </div>
                <div className="mxd-pricing-table__tag">
                  <span className="tag tag-default tag-additional">🔥 Hot</span>
                </div>
              </Link>
            </div>
            {/* items row */}
            <div className="mxd-demo-list__row">
              {/* single item */}
              <Link
                className="mxd-demo-list__item animate-card-3"
                href={`/home-freelancer-portfolio`}
                target="_blank"
              >
                <div className="mxd-demo-list__image">
                  <Image
                    alt="Rayo Demo Screen"
                    width={1000}
                    height={628}
                    src="/img/demo/screens/03.webp"
                  />
                  <div className="mxd-demo-list__screen screen-03" />
                </div>
                <div className="mxd-demo-list__caption">
                  <span className="mxd-demo-list__link small">
                    Freelancer Portfolio
                  </span>
                  <span className="mxd-demo-list__num small">/03</span>
                </div>
              </Link>
              {/* single item */}
              <Link
                className="mxd-demo-list__item animate-card-3"
                href={`/home-digital-agency`}
                target="_blank"
              >
                <div className="mxd-demo-list__image">
                  <Image
                    alt="Rayo Demo Screen"
                    width={1000}
                    height={628}
                    src="/img/demo/screens/04.webp"
                  />
                  <div className="mxd-demo-list__screen screen-04" />
                </div>
                <div className="mxd-demo-list__caption">
                  <span className="mxd-demo-list__link small">
                    Digital Agency
                  </span>
                  <span className="mxd-demo-list__num small">/04</span>
                </div>
              </Link>
              {/* single item */}
              <Link
                className="mxd-demo-list__item animate-card-3"
                href={`/home-creative-design-studio`}
                target="_blank"
              >
                <div className="mxd-demo-list__image">
                  <Image
                    alt="Rayo Demo Screen"
                    width={1000}
                    height={628}
                    src="/img/demo/screens/05.webp"
                  />
                  <div className="mxd-demo-list__screen screen-05" />
                </div>
                <div className="mxd-demo-list__caption">
                  <span className="mxd-demo-list__link small">
                    Creative Design Studio
                  </span>
                  <span className="mxd-demo-list__num small">/05</span>
                </div>
              </Link>
            </div>
            {/* items row */}
            <div className="mxd-demo-list__row">
              {/* single item */}
              <Link
                className="mxd-demo-list__item item-accent animate-card-2"
                href={`/home-personal-portfolio`}
                target="_blank"
              >
                <div className="mxd-demo-list__image">
                  <Image
                    alt="Rayo Demo Screen"
                    width={1000}
                    height={628}
                    src="/img/demo/screens/06.webp"
                  />
                  <div className="mxd-demo-list__screen screen-06" />
                </div>
                <div className="mxd-demo-list__caption">
                  <span className="mxd-demo-list__link opposite">
                    Personal Portfolio
                  </span>
                  <span className="mxd-demo-list__num opposite">/06</span>
                </div>
                <div className="mxd-pricing-table__tag">
                  <span className="tag tag-default tag-additional">🔥 Hot</span>
                </div>
              </Link>
              {/* single item */}
              <Link
                className="mxd-demo-list__item animate-card-2"
                href={`/home-web-agency`}
                target="_blank"
              >
                <div className="mxd-demo-list__image">
                  <Image
                    alt="Rayo Demo Screen"
                    width={1000}
                    height={628}
                    src="/img/demo/screens/07.webp"
                  />
                  <div className="mxd-demo-list__screen screen-07" />
                </div>
                <div className="mxd-demo-list__caption">
                  <span className="mxd-demo-list__link">Web Agency</span>
                  <span className="mxd-demo-list__num">/07</span>
                </div>
                <div className="mxd-pricing-table__tag">
                  <span className="tag tag-default tag-accent">🦄 Trendy</span>
                </div>
              </Link>
            </div>
            {/* items row */}
            <div className="mxd-demo-list__row">
              {/* single item */}
              <Link
                className="mxd-demo-list__item animate-card-3"
                href={`/home-creative-developer`}
                target="_blank"
              >
                <div className="mxd-demo-list__image">
                  <Image
                    alt="Rayo Demo Screen"
                    width={1000}
                    height={628}
                    src="/img/demo/screens/08.webp"
                  />
                  <div className="mxd-demo-list__screen screen-08" />
                </div>
                <div className="mxd-demo-list__caption">
                  <span className="mxd-demo-list__link small">
                    Creative Developer
                  </span>
                  <span className="mxd-demo-list__num small">/08</span>
                </div>
              </Link>
              {/* single item */}
              <Link
                className="mxd-demo-list__item animate-card-3"
                href={`/home-designer`}
                target="_blank"
              >
                <div className="mxd-demo-list__image">
                  <Image
                    alt="Rayo Demo Screen"
                    width={1000}
                    height={628}
                    src="/img/demo/screens/09.webp"
                  />
                  <div className="mxd-demo-list__screen screen-09" />
                </div>
                <div className="mxd-demo-list__caption">
                  <span className="mxd-demo-list__link small">Designer</span>
                  <span className="mxd-demo-list__num small">/09</span>
                </div>
              </Link>
              {/* single item */}
              <div className="mxd-demo-list__item empty-item animate-card-3">
                <div className="mxd-demo-list__image image-placeholder">
                  <Image
                    alt="Rayo Demo Screen"
                    width={1000}
                    height={628}
                    src="/img/demo/screens/05.webp"
                  />
                </div>
                <div className="empty-item__wrap">
                  <div className="empty-item__content">
                    <div className="empty-item__logo mxd-rotate">
                      {/* logo icon */}
                      <svg
                        className="empty-item__image"
                        version="1.1"
                        xmlns="http://www.w3.org/2000/svg"
                        x="0px"
                        y="0px"
                        viewBox="0 0 56 56"
                        enableBackground="new 0 0 56 56"
                        xmlSpace="preserve"
                      >
                        <style
                          type="text/css"
                          dangerouslySetInnerHTML={{
                            __html:
                              "\n                            .mxd-logo-colored__bg {\n                              fill: var(--accent);\n                            }\n                            .mxd-logo-colored__cat {\n                              clip-path: url(#mxd-logo__crop);\n                              fill: var(--additional);\n                            }\n                          ",
                          }}
                        />
                        <path
                          className="mxd-logo-colored__bg"
                          d="M56,28c0,11.1-2.9,28-28,28S0,39.1,0,28S2.9,0,28,0S56,16.9,56,28z"
                        />
                        <g>
                          <defs>
                            <path
                              id="mxd-logo__clip"
                              d="M28,0C2.9,0,0,16.9,0,28s2.9,28,28,28s28-16.9,28-28S53.1,0,28,0z"
                            />
                          </defs>
                          <clipPath id="mxd-logo__crop">
                            <use
                              xlinkHref="#mxd-logo__clip"
                              style={{ overflow: "visible" }}
                            />
                          </clipPath>
                          <path
                            className="mxd-logo-colored__cat"
                            d="M33.6,34.5h0.9
                        c0.5,0,0.9,0.4,0.9,0.9v3.7c0,0.5-0.4,0.9-0.9,0.9h-0.9c-0.5,0-0.9-0.4-0.9-0.9v-3.7C32.7,34.9,33.1,34.5,33.6,34.5z M20.5,37.3
                        v1.9c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9-0.4,0.9-0.9v-3.7c0-0.5-0.4-0.9-0.9-0.9h-0.9c-0.5,0-0.9,0.4-0.9,0.9V37.3L20.5,37.3z
                        M39.2,21.5v0.9c0,0.5-0.4,0.9-0.9,0.9h-0.9c-0.5,0-0.9-0.4-0.9-0.9v-0.9c0-0.5,0.4-0.9,0.9-0.9h0.9C38.8,20.5,39.2,21,39.2,21.5z
                        M34.5,26.1h0.9c0.5,0,0.9-0.4,0.9-0.9v-0.9c0-0.5-0.4-0.9-0.9-0.9h-0.9c-0.5,0-0.9,0.4-0.9,0.9v0.9C33.6,25.7,34,26.1,34.5,26.1z
                        M28,26.1h-4.7c-0.5,0-0.9,0.4-0.9,0.9V28c0,0.5,0.4,0.9,0.9,0.9h9.3c0.5,0,0.9-0.4,0.9-0.9v-0.9c0-0.5-0.4-0.9-0.9-0.9H28L28,26.1
                        z M19.6,24.3v0.9c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9-0.4,0.9-0.9v-0.9c0-0.5-0.4-0.9-0.9-0.9h-0.9C20,23.3,19.6,23.8,19.6,24.3z
                        M16.8,21.5v0.9c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9-0.4,0.9-0.9v-0.9c0-0.5-0.4-0.9-0.9-0.9h-0.9C17.2,20.5,16.8,21,16.8,21.5z
                        M14,26.1v4.7c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9-0.4,0.9-0.9v-6.5c0-0.5-0.4-0.9-0.9-0.9h-0.9c-0.5,0-0.9,0.4-0.9,0.9V26.1
                        L14,26.1z M11.2,34.5v1.9c0,0.5-0.4,0.9-0.9,0.9H7.5c-0.5,0-0.9,0.4-0.9,0.9v0.9c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9,0.4,0.9,0.9
                        V42c0,0.5-0.4,0.9-0.9,0.9H7.5c-0.5,0-0.9,0.4-0.9,0.9v0.9c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9,0.4,0.9,0.9V56
                        c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9-0.4,0.9-0.9v-6.5c0-0.5,0.4-0.9,0.9-0.9h3.7c0.5,0,0.9-0.4,0.9-0.9v-0.9
                        c0-0.5-0.4-0.9-0.9-0.9h-3.7c-0.5,0-0.9-0.4-0.9-0.9v-6.5c0-0.5,0.4-0.9,0.9-0.9c0.5,0,0.9-0.4,0.9-0.9v-3.7c0-0.5-0.4-0.9-0.9-0.9
                        h-0.9c-0.5,0-0.9,0.4-0.9,0.9L11.2,34.5L11.2,34.5z M42,26.1v-1.9c0-0.5-0.4-0.9-0.9-0.9h-0.9c-0.5,0-0.9,0.4-0.9,0.9v6.5
                        c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9-0.4,0.9-0.9V26.1L42,26.1z M49.5,39.2v-0.9c0-0.5-0.4-0.9-0.9-0.9h-2.8
                        c-0.5,0-0.9-0.4-0.9-0.9v-3.7c0-0.5-0.4-0.9-0.9-0.9h-0.9c-0.5,0-0.9,0.4-0.9,0.9v3.7c0,0.5,0.4,0.9,0.9,0.9c0.5,0,0.9,0.4,0.9,0.9
                        v6.5c0,0.5-0.4,0.9-0.9,0.9h-3.7c-0.5,0-0.9,0.4-0.9,0.9v0.9c0,0.5,0.4,0.9,0.9,0.9h3.7c0.5,0,0.9,0.4,0.9,0.9V56
                        c0,0.5,0.4,0.9,0.9,0.9h0.9c0.5,0,0.9-0.4,0.9-0.9v-9.3c0-0.5,0.4-0.9,0.9-0.9h0.9c0.5,0,0.9-0.4,0.9-0.9v-0.9
                        c0-0.5-0.4-0.9-0.9-0.9h-0.9c-0.5,0-0.9-0.4-0.9-0.9v-0.9c0-0.5,0.4-0.9,0.9-0.9h0.9C49,40.1,49.5,39.7,49.5,39.2L49.5,39.2z"
                          />
                        </g>
                      </svg>
                    </div>
                    <p className="empty-item__caption">
                      More demos coming
                      <br />
                      soon...
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        {/* Block - Demo List End */}
      </div>
    </div>
  );
}
