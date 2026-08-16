import type { Metadata, Viewport } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import "./globals.css";
import { FeedbackProvider } from "@/components/feedback";
import { PwaRegister } from "@/components/pwa-register";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "Mr Jeff Stock",
  description: "Multi-shop phone stock & sales management",
  manifest: "/manifest.webmanifest",
  applicationName: "Mr Jeff Stock",
  appleWebApp: {
    capable: true,
    title: "Jeff Stock",
    statusBarStyle: "default",
  },
  icons: {
    icon: "/icon.svg",
    apple: "/apple-touch-icon.png",
  },
};

export const viewport: Viewport = {
  themeColor: "#18181b",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="en"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
    >
      <body className="min-h-full flex flex-col">
        <FeedbackProvider>{children}</FeedbackProvider>
        <PwaRegister />
      </body>
    </html>
  );
}
