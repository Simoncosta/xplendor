import BlogDetails from "@/components/blogs/BlogDetails";
import Blogs from "@/components/common/Blogs";
import Cta from "@/components/common/Cta";
import Footer2 from "@/components/footers/Footer2";
import { Metadata } from "next";
export const metadata: Metadata = {
  title:
    "Blog Details || Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
  description:
    "Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
};
export default function BlogSinglePage() {
  return (
    <>
      <main
        id="mxd-page-content"
        className="mxd-page-content inner-page-content"
      >
        <BlogDetails />
        <Blogs desc="" title="More on topic" />
        <Cta />
      </main>
      <Footer2 />
    </>
  );
}
