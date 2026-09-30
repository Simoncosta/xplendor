import TypedText from "@/components/common/TypedText";

export default function Hero() {
  return (
    <div className="mxd-section mxd-section-inner-headline padding-text-pre-block">
      <div className="mxd-container grid-container">
        {/* Block - Inner Page Headline Start */}
        <div className="mxd-block loading-wrap">
          <div className="container-fluid px-0">
            <div className="row gx-0">
              {/* Inner Headline Name Start */}
              <div className="col-12 col-xl-2 mxd-grid-item no-margin">
                <div className="mxd-block__name name-inner-headline loading__item">
                  <p className="mxd-point-subtitle">
                    <svg
                      version="1.1"
                      xmlns="http://www.w3.org/2000/svg"
                      x="0px"
                      y="0px"
                      width="20px"
                      height="20px"
                      viewBox="0 0 20 20"
                      fill="currentColor"
                    >
                      <path
                        fill="currentColor"
                        d="M19.6,9.6c0,0-3,0-4,0c-0.4,0-1.8-0.2-1.8-0.2c-0.6-0.1-1.1-0.2-1.6-0.6c-0.5-0.3-0.9-0.8-1.2-1.2
                    c-0.3-0.4-0.4-0.9-0.5-1.4c0,0-0.1-1.1-0.2-1.5c-0.1-1.1,0-4.4,0-4.4C10.4,0.2,10.2,0,10,0S9.6,0.2,9.6,0.4c0,0,0.1,3.3,0,4.4
                    c0,0.4-0.2,1.5-0.2,1.5C9.4,6.7,9.2,7.2,9,7.6C8.7,8.1,8.2,8.5,7.8,8.9c-0.5,0.3-1,0.5-1.6,0.6c0,0-1.2,0.1-1.7,0.2
                    c-1,0.1-4.2,0-4.2,0C0.2,9.6,0,9.8,0,10c0,0.2,0.2,0.4,0.4,0.4c0,0,3.1-0.1,4.2,0c0.4,0,1.7,0.2,1.7,0.2c0.6,0.1,1.1,0.2,1.6,0.6
                    c0.4,0.3,0.8,0.7,1.1,1.1c0.3,0.5,0.5,1,0.6,1.6c0,0,0.1,1.3,0.2,1.7c0,1,0,4.1,0,4.1c0,0.2,0.2,0.4,0.4,0.4s0.4-0.2,0.4-0.4
                    c0,0,0-3.1,0-4.1c0-0.4,0.2-1.7,0.2-1.7c0.1-0.6,0.2-1.1,0.6-1.6c0.3-0.4,0.7-0.8,1.1-1.1c0.5-0.3,1-0.5,1.6-0.6
                    c0,0,1.3-0.1,1.8-0.2c1,0,4,0,4,0c0.2,0,0.4-0.2,0.4-0.4C20,9.8,19.8,9.6,19.6,9.6L19.6,9.6z"
                      />
                    </svg>
                    <span>About me</span>
                  </p>
                </div>
              </div>
              {/* Inner Headline Name Start */}
              {/* Inner Headline Content Start */}
              <div className="col-12 col-xl-8 mxd-grid-item no-margin">
                <div className="mxd-block__content">
                  <div className="mxd-block__inner-headline">
                    <h1 className="inner-headline__title animated-type loading__item">
                      <TypedText />
                    </h1>
                    <p className="inner-headline__text t-large t-bright loading__item">
                      👋 Hey! I am Alex Walker. I&apos;m passionate about
                      crafting designs that tell stories, spark emotions, and
                      make an impact. Whether I&apos;m crafting digital
                      experiences or creating illustrations, I pour creativity
                      and precision into every detail.
                    </p>
                  </div>
                </div>
              </div>
              {/* Inner Headline Content End */}
            </div>
          </div>
        </div>
        {/* Block - Inner Page Headline End */}
      </div>
    </div>
  );
}
