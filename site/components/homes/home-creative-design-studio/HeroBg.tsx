import Image from "next/image";

export default function HeroBg() {
  return (
    <div className="mxd-page-bg hero-04-bg loading__fade">
      <div className="hero-04-bg__obj-01" data-speed="0.5">
        <Image
          className="mxd-move"
          alt="Background Image"
          src="/img/hero/05_hero-img.webp"
          width={800}
          height={800}
        />
      </div>
      <div className="hero-04-bg__obj-02" data-speed="0.7">
        <Image
          className="mxd-move"
          alt="Background Image"
          src="/img/hero/05_hero-img.webp"
          width={800}
          height={800}
        />
      </div>
    </div>
  );
}
