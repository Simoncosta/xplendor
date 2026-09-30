import VelocityMarquee from "@/components/animation/VelocityMarquee";

export default function MarqueeSlider() {
  return (
    <div className="mxd-section padding-mtext-pre-stack">
      <div className="mxd-container fullwidth-container">
        {/* Block - Marquee Text One Line Start */}
        <div className="mxd-block">
          <VelocityMarquee className="marquee marquee-right--gsap muted-extra">
            {/* single item */}
            {Array.from({ length: 3 }).map((_, index) => (
              <div
                key={index}
                className="marquee__item one-line item-regular text"
              >
                <p className="marquee__text">I Can Do Best</p>
                <div className="marquee__image">
                  <svg
                    version="1.1"
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 80 80"
                    fill="currentColor"
                  >
                    <path
                      fill="currentColor"
                      d="M78.4,38.4c0,0-11.8,0-15.8,0c-1.6,0-4.8-0.2-7.1-0.8c-2.3-0.6-4.3-0.8-6.3-2.4c-2-1.2-3.5-3.2-4.7-4.8
                  c-1.2-1.6-1.6-3.6-2-5.5c-0.3-1.5-0.7-4.3-0.8-5.9c-0.2-4.3,0-17.4,0-17.4C41.8,0.8,41,0,40.2,0s-1.6,0.8-1.6,1.6c0,0,0,13.1,0,17.4
                  c0,1.6-0.6,4.3-0.8,5.9c-0.3,2-0.8,4-2,5.5c-1.2,2-2.8,3.6-4.7,4.8s-4,1.8-6.3,2.4c-1.9,0.5-4.7,0.6-6.7,0.8c-3.9,0.4-16.6,0-16.6,0
                  C0.8,38.4,0,39.2,0,40c0,0.8,0.8,1.6,1.6,1.6c0,0,12.2,0,16.6,0c1.6,0,4.8,0.3,6.7,0.8c2.3,0.6,4.3,0.8,6.3,2.4
                  c1.6,1.2,3.2,2.8,4.3,4.4c1.2,2,2.1,3.9,2.4,6.3c0.2,1.7,0.7,4.7,0.8,6.7c0.2,4,0,16.2,0,16.2c0,0.8,0.8,1.6,1.6,1.6
                  s1.6-0.8,1.6-1.6c0,0,0-12.3,0-16.2c0-1.6,0.5-5.1,0.8-6.7c0.5-2.3,0.8-4.4,2.4-6.3c1.2-1.6,2.8-3.2,4.3-4.4c2-1.2,3.9-2,6.3-2.4
                  c1.8-0.3,5.1-0.7,7.1-0.8c3.5-0.2,15.8,0,15.8,0c0.8,0,1.6-0.8,1.6-1.6C80,39.2,79.2,38.4,78.4,38.4C78.4,38.4,78.4,38.4,78.4,38.4z
                  "
                    />
                  </svg>
                </div>
              </div>
            ))}
          </VelocityMarquee>
        </div>
        {/* Block - Marquee Text One Line End */}
      </div>
    </div>
  );
}
