import AnimatedButton from "@/components/animation/AnimatedButton";
import Counter from "@/components/common/Counter";
import Image from "next/image";

export default function Hero() {
  return (
    <div className="mxd-section">
      <div className="mxd-hero-09">
        <div className="mxd-hero-09__wrap loading-wrap">
          <div className="mxd-hero-09__headline">
            <div className="hero-09-headline__caption loading__item">
              <p>
                Award-winning creative projects, over ten years of digital
                experience.
              </p>
            </div>
            <div className="hero-09-headline__title loading__item">
              <h1 className="hero-09-headline__hidden">Alex Walker</h1>
              <svg
                className="hero-09-svg-mobile"
                xmlns="http://www.w3.org/2000/svg"
                xmlnsXlink="http://www.w3.org/1999/xlink"
                x="0px"
                y="0px"
                viewBox="0 0 153.2 74.1"
                enableBackground={"new 0 0 153.2 74.1"}
                xmlSpace="preserve"
              >
                <path
                  d="M9.7,34c-2.8,0-5-0.7-6.6-2c-1.6-1.4-2.4-3.2-2.4-5.4c0-2.3,0.8-4.2,2.4-5.4c1.6-1.3,3.9-1.9,7-1.9h7.3v-1
            c0-2.9-1.7-4.4-5.1-4.4c-2.9,0-4.7,1-5.2,3.1h-6c0.5-2.6,1.7-4.5,3.6-5.9c1.9-1.3,4.4-2,7.6-2c3.5,0,6.2,0.8,8,2.4
            c1.8,1.6,2.8,3.9,2.8,6.9v9.2H26v6h-6.1v-3.8h-1.7c-0.8,1.4-1.9,2.4-3.3,3.2S11.7,34,9.7,34z M10.6,29.4c1.3,0,2.5-0.2,3.5-0.7
            c1-0.5,1.9-1.2,2.5-2c0.6-0.8,0.9-1.8,0.9-2.8v-0.6h-7c-2.7,0-4,1-4,3c0,1,0.4,1.8,1.1,2.3C8.2,29.2,9.3,29.4,10.6,29.4z M30.6,33.6
            V0h6v33.6H30.6z M53.6,34.1c-2.6,0-4.8-0.5-6.7-1.5c-1.9-1-3.4-2.5-4.4-4.4c-1-1.9-1.5-4.1-1.5-6.7s0.5-4.8,1.5-6.6
            c1-1.9,2.5-3.3,4.3-4.3c1.9-1,4.1-1.5,6.6-1.5c2.5,0,4.6,0.5,6.3,1.4c1.8,0.9,3.1,2.3,4.1,4.1c1,1.8,1.4,3.9,1.4,6.3V23H46.7
            c0.2,2,1,3.6,2.1,4.7c1.2,1.1,2.8,1.6,4.7,1.6c1.5,0,2.7-0.3,3.6-0.8s1.6-1.4,1.9-2.5h6c-0.6,2.5-1.8,4.5-3.9,5.9
            C59.2,33.4,56.7,34.1,53.6,34.1z M46.8,19h12.6c-0.2-1.7-0.8-2.9-1.9-3.8c-1.1-0.9-2.5-1.3-4.2-1.3c-1.7,0-3.2,0.4-4.3,1.3
            S47.2,17.3,46.8,19z M66.2,33.6l9.4-12.3L66.7,9.6h6.6l5.8,7.6l5.8-7.6h6.6l-8.9,11.7l9.4,12.3h-6.7L79,25.4l-6.2,8.2H66.2z
            M7.7,73.6L0,49.6h6.1l5,16.6h0.2l5.9-16.6H23l5.9,16.6h0.2l5-16.6h6l-7.7,24h-6.4l-5.9-16.3H20l-5.9,16.3H7.7z M50.5,74
            c-2.8,0-5-0.7-6.6-2c-1.6-1.4-2.4-3.2-2.4-5.4c0-2.3,0.8-4.2,2.4-5.4c1.6-1.3,3.9-1.9,7-1.9h7.3v-1c0-2.9-1.7-4.4-5.1-4.4
            c-2.9,0-4.7,1-5.2,3.1h-6c0.5-2.6,1.7-4.5,3.6-5.9s4.4-2,7.6-2c3.5,0,6.2,0.8,8,2.4s2.8,3.9,2.8,6.9v9.2h2.8v6h-6.1v-3.8H59
            c-0.8,1.4-1.9,2.4-3.3,3.2S52.5,74,50.5,74z M51.4,69.4c1.3,0,2.5-0.2,3.5-0.7c1-0.5,1.9-1.2,2.5-2c0.6-0.8,0.9-1.8,0.9-2.8v-0.6h-7
            c-2.7,0-4,1-4,3c0,1,0.4,1.8,1.1,2.3C49.1,69.2,50.1,69.4,51.4,69.4z M71.4,73.6V40h6v33.6H71.4z M83.1,73.6V40h6v18.9h0.2l10.1-9.3
            h7.1L96,59l11.3,14.6h-7l-8.4-10.9L89.1,65v8.6H83.1z M120.7,74.1c-2.6,0-4.8-0.5-6.7-1.5c-1.9-1-3.4-2.5-4.4-4.4s-1.5-4.1-1.5-6.7
            c0-2.6,0.5-4.8,1.5-6.6c1-1.9,2.5-3.3,4.3-4.3c1.9-1,4.1-1.5,6.6-1.5c2.5,0,4.6,0.5,6.3,1.4s3.1,2.3,4.1,4.1c1,1.8,1.4,3.9,1.4,6.3
            V63h-18.5c0.2,2,1,3.6,2.1,4.7c1.2,1.1,2.8,1.6,4.7,1.6c1.5,0,2.7-0.3,3.6-0.8s1.6-1.4,1.9-2.5h6c-0.6,2.5-1.8,4.5-3.9,5.9
            C126.3,73.4,123.8,74.1,120.7,74.1z M114,59h12.6c-0.2-1.7-0.8-2.9-1.9-3.8c-1.1-0.9-2.5-1.3-4.2-1.3c-1.7,0-3.2,0.4-4.3,1.3
            C115.1,56.1,114.3,57.3,114,59z M138.5,73.6v-18h-3v-6h6.1v4.6h1.7c0.7-1.6,1.7-2.8,3.1-3.6c1.4-0.8,3-1.2,4.8-1.2h2v5.1h-2.3
            c-2.2,0-3.8,0.6-4.9,1.9c-1.1,1.3-1.6,2.9-1.6,5v12.2H138.5z"
                />
              </svg>
              <svg
                className="hero-09-svg-desktop"
                xmlns="http://www.w3.org/2000/svg"
                xmlnsXlink="http://www.w3.org/1999/xlink"
                x="0px"
                y="0px"
                viewBox="0 0 257.3 34.1"
                enableBackground={"new 0 0 257.3 34.1"}
                xmlSpace="preserve"
              >
                <path
                  d="M9,34c-2.8,0-5-0.7-6.6-2C0.8,30.6,0,28.8,0,26.6c0-2.3,0.8-4.2,2.4-5.4c1.6-1.3,3.9-1.9,7-1.9h7.3v-1
            c0-2.9-1.7-4.4-5.1-4.4c-2.9,0-4.7,1-5.2,3.1h-6c0.5-2.6,1.7-4.5,3.6-5.9c1.9-1.3,4.4-2,7.6-2c3.5,0,6.2,0.8,8,2.4
            c1.8,1.6,2.8,3.9,2.8,6.9v9.2h2.8v6h-6.1v-3.8h-1.7c-0.8,1.4-1.9,2.4-3.3,3.2C12.7,33.7,11,34,9,34z M9.8,29.4
            c1.3,0,2.5-0.2,3.5-0.7c1-0.5,1.9-1.2,2.5-2c0.6-0.8,0.9-1.8,0.9-2.8v-0.6h-7c-2.7,0-4,1-4,3c0,1,0.4,1.8,1.1,2.3
            C7.5,29.2,8.5,29.4,9.8,29.4z M29.8,33.6V0h6v33.6H29.8z M52.9,34.1c-2.6,0-4.8-0.5-6.7-1.5c-1.9-1-3.4-2.5-4.4-4.4
            c-1-1.9-1.5-4.1-1.5-6.7s0.5-4.8,1.5-6.6c1-1.9,2.5-3.3,4.3-4.3c1.9-1,4.1-1.5,6.6-1.5c2.5,0,4.6,0.5,6.3,1.4
            c1.8,0.9,3.1,2.3,4.1,4.1c1,1.8,1.4,3.9,1.4,6.3V23H46c0.2,2,1,3.6,2.1,4.7s2.8,1.6,4.7,1.6c1.5,0,2.7-0.3,3.6-0.8
            c0.9-0.5,1.6-1.4,1.9-2.5h6c-0.6,2.5-1.8,4.5-3.9,5.9C58.5,33.4,55.9,34.1,52.9,34.1z M46.1,19h12.6c-0.2-1.7-0.8-2.9-1.9-3.8
            c-1.1-0.9-2.5-1.3-4.2-1.3c-1.7,0-3.2,0.4-4.3,1.3S46.5,17.3,46.1,19z M65.5,33.6l9.4-12.3L66,9.6h6.6l5.8,7.6l5.8-7.6h6.6
            l-8.9,11.7l9.4,12.3h-6.7l-6.2-8.2l-6.2,8.2H65.5z M111.9,33.6l-7.7-24h6.1l5,16.6h0.2l5.9-16.6h5.7l5.9,16.6h0.2l5-16.6h6l-7.7,24
            h-6.4l-5.9-16.3h-0.2l-5.9,16.3H111.9z M154.7,34c-2.8,0-5-0.7-6.6-2c-1.6-1.4-2.4-3.2-2.4-5.4c0-2.3,0.8-4.2,2.4-5.4
            c1.6-1.3,3.9-1.9,7-1.9h7.3v-1c0-2.9-1.7-4.4-5.1-4.4c-2.9,0-4.7,1-5.2,3.1h-6c0.5-2.6,1.7-4.5,3.6-5.9c1.9-1.3,4.4-2,7.6-2
            c3.5,0,6.2,0.8,8,2.4c1.8,1.6,2.8,3.9,2.8,6.9v9.2h2.8v6h-6.1v-3.8h-1.7c-0.8,1.4-1.9,2.4-3.3,3.2C158.4,33.7,156.7,34,154.7,34z
            M155.6,29.4c1.3,0,2.5-0.2,3.5-0.7c1-0.5,1.9-1.2,2.5-2c0.6-0.8,0.9-1.8,0.9-2.8v-0.6h-7c-2.7,0-4,1-4,3c0,1,0.4,1.8,1.1,2.3
            C153.2,29.2,154.3,29.4,155.6,29.4z M175.6,33.6V0h6v33.6H175.6z M187.3,33.6V0h6v18.9h0.2l10.1-9.3h7.1L200.2,19l11.3,14.6h-7
            L196,22.7l-2.8,2.3v8.6H187.3z M224.9,34.1c-2.6,0-4.8-0.5-6.7-1.5c-1.9-1-3.4-2.5-4.4-4.4s-1.5-4.1-1.5-6.7s0.5-4.8,1.5-6.6
            c1-1.9,2.5-3.3,4.3-4.3c1.9-1,4.1-1.5,6.6-1.5c2.5,0,4.6,0.5,6.3,1.4c1.8,0.9,3.1,2.3,4.1,4.1c1,1.8,1.4,3.9,1.4,6.3V23H218
            c0.2,2,1,3.6,2.1,4.7s2.8,1.6,4.7,1.6c1.5,0,2.7-0.3,3.6-0.8c0.9-0.5,1.6-1.4,1.9-2.5h6c-0.6,2.5-1.8,4.5-3.9,5.9
            C230.5,33.4,227.9,34.1,224.9,34.1z M218.1,19h12.6c-0.2-1.7-0.8-2.9-1.9-3.8c-1.1-0.9-2.5-1.3-4.2-1.3c-1.7,0-3.2,0.4-4.3,1.3
            C219.2,16.1,218.5,17.3,218.1,19z M242.7,33.6v-18h-3v-6h6.1v4.6h1.7c0.7-1.6,1.7-2.8,3.1-3.6c1.4-0.8,3-1.2,4.8-1.2h2v5.1H255
            c-2.2,0-3.8,0.6-4.9,1.9c-1.1,1.3-1.6,2.9-1.6,5v12.2H242.7z"
                />
              </svg>
            </div>
            <div className="hero-09-headline__tags loading__fade">
              <span className="tag tag-default tag-outline">Midjourney</span>
              <span className="tag tag-default tag-outline">AI</span>
              <span className="tag tag-default tag-outline">Editorial</span>
              <span className="tag tag-default tag-outline">
                Interactive design
              </span>
              <span className="tag tag-default tag-outline">
                Web experience
              </span>
              <span className="tag tag-default tag-outline">Web design</span>
              <span className="tag tag-default tag-outline">Branding</span>
              <span className="tag tag-default tag-outline">UI</span>
              <span className="tag tag-default tag-outline">UX</span>
              <span className="tag tag-default tag-outline">HTML</span>
              <span className="tag tag-default tag-outline">Frontend</span>
              <span className="tag tag-default tag-outline">Motion design</span>
              <span className="tag tag-default tag-outline">3d modeling</span>
              <span className="tag tag-default tag-outline">Video editing</span>
            </div>
          </div>
          <div className="mxd-hero-09__objects">
            <div className="hero-09-objects__image loading__item">
              <Image
                className="mxd-move"
                alt="Hero Image"
                src="/img/hero/22_hero-img.webp"
                width={915}
                height={1157}
              />
            </div>
            <div className="hero-09-objects__item item-01 loading__item">
              <div className="mxd-counter horizontal">
                <p
                  id="stats-counter-1"
                  className="mxd-counter__number mxd-stats-number xsmall"
                >
                  <Counter max={3} />+
                </p>
                <p className="mxd-counter__descr t-140 t-bright t-small">
                  Years of
                  <br />
                  experience
                </p>
              </div>
            </div>
            <div className="hero-09-objects__item item-02 loading__item">
              <div className="mxd-counter horizontal">
                <p
                  id="stats-counter-2"
                  className="mxd-counter__number mxd-stats-number xsmall"
                >
                  <Counter max={70} />+
                </p>
                <p className="mxd-counter__descr t-140 t-bright t-small">
                  Successful
                  <br />
                  projects
                </p>
              </div>
            </div>
            <div className="hero-09-objects__item item-03 loading__item">
              <div className="mxd-hero__mark">
                <span className="mark-icon" />
                <span className="mark-text">Available for freelance</span>
              </div>
            </div>
          </div>
          {/* scroll for more */}
          <div className="mxd-hero-09__more loading__fade">
            <AnimatedButton
              text="Scroll for more"
              as={"a"}
              className="btn btn-line-default btn-anim slide-down"
              href="#projects"
            >
              <i className="ph-bold ph-arrow-elbow-right-down" />
            </AnimatedButton>
          </div>
        </div>
      </div>
    </div>
  );
}
