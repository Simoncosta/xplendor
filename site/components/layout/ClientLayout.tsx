"use client";

import MobileMenu from "@/components/headers/MobileMenu";
import Header1 from "@/components/headers/Header1";
import InitScroll from "@/components/scroll/InitScroll";
import LenisSmoothScroll from "@/components/scroll/LenisSmoothScroll";
import ScrollTop from "@/components/scroll/ScrollTop";
import ScrollToTopOnRouteChange from "@/components/scroll/ScrollToTopOnRouteChange";
import WhatsAppChat from "@/components/common/WhatsAppChat";

interface ClientLayoutProps {
  children: React.ReactNode;
}

export default function ClientLayout({ children }: ClientLayoutProps) {
  return (
    <>
      <MobileMenu />
      <Header1 />
      {children}
      <InitScroll />
      <ScrollTop />
      <WhatsAppChat />
      <LenisSmoothScroll />
      <ScrollToTopOnRouteChange />
    </>
  );
}
