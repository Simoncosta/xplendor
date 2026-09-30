import Image from "next/image";

import VelocityMarquee from "../animation/VelocityMarquee";
import RevealText from "../animation/RevealText";

export default function PagesMarquee() {
  return (
    <div
      className="mxd-section"
      style={{ maxWidth: "100vw", overflowX: "hidden" }}
    >
      <div className="mxd-container fullwidth-container">
        {/* Block - Components Start */}
        <div className="mxd-block">
          {/* marquee top to left */}
          <VelocityMarquee direction="left" className="marquee__toleft">
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-01.webp"
                />
              </div>
            </div>
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-02.webp"
                />
              </div>
            </div>
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-03.webp"
                />
              </div>
            </div>
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-04.webp"
                />
              </div>
            </div>
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-05.webp"
                />
              </div>
            </div>
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-06.webp"
                />
              </div>
            </div>
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-07.webp"
                />
              </div>
            </div>
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-08.webp"
                />
              </div>
            </div>
          </VelocityMarquee>
          {/* block title */}
          <div className="mxd-container">
            <div className="mxd-demo-components">
              <div className="mxd-demo-components__objects">
                <div className="mxd-demo-components__object obj-01 anim-uni-in-up">
                  <Image
                    className="mxd-move-slow"
                    alt="Rayo Image"
                    width={700}
                    height={730}
                    src="/img/demo/01_comp-img.webp"
                  />
                </div>
                <div className="mxd-demo-components__object obj-02 anim-uni-in-up">
                  <Image
                    className="mxd-rotate-slow"
                    alt="Rayo Image"
                    width={700}
                    height={730}
                    src="/img/demo/01_comp-img.webp"
                  />
                </div>
                <div className="mxd-demo-components__object obj-03 anim-uni-in-up">
                  <Image
                    className="mxd-move"
                    alt="Rayo Image"
                    width={700}
                    height={730}
                    src="/img/demo/01_comp-img.webp"
                  />
                </div>
              </div>
              <div className="mxd-demo-components__title anim-uni-in-up">
                <RevealText as="span" className="reveal-type">
                  Functional
                  <br />
                  components
                </RevealText>
              </div>
            </div>
          </div>
          {/* marquee bottom to right */}
          <VelocityMarquee className="marquee__toright">
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-09.webp"
                />
              </div>
            </div>
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-10.webp"
                />
              </div>
            </div>
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-11.webp"
                />
              </div>
            </div>
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-04.webp"
                />
              </div>
            </div>
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-14.webp"
                />
              </div>
            </div>
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-06.webp"
                />
              </div>
            </div>
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-15.webp"
                />
              </div>
            </div>
            {/* single item */}
            <div className="marquee__item overflow-visible one-line item-regular text">
              <div className="marquee__component">
                <Image
                  alt="Rayo Image"
                  width={800}
                  height={502}
                  src="/img/demo/components/sec-08.webp"
                />
              </div>
            </div>
          </VelocityMarquee>
        </div>
        {/* Block - Components End */}
      </div>
    </div>
  );
}
