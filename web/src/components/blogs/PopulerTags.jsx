const tags = [
  { href: "#", text: "AutoDecar" },
  { href: "#", text: "BMW" },
  { href: "#", text: "Design" },
  { href: "#", text: "Themesflat" },
  { href: "#", text: "Land rover" },
  { href: "#", text: "KIA" },
  { href: "#", text: "Road" },
];

export default function PopulerTags() {
  return (
    <div className="widget widget-tags">
      <h3 className="widget-title title-tags">Popular tags</h3>
      <div className="tags_cloud_inner">
        {tags.map((link, index) => (
          <a key={index} href={link.href}>
            {link.text}
          </a>
        ))}
      </div>
    </div>
  );
}
