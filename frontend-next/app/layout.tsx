import type { Metadata } from "next";
import { IBM_Plex_Mono, Space_Grotesk } from "next/font/google";
import "./globals.css";

const titleFont = Space_Grotesk({
  subsets: ["latin"],
  variable: "--font-title",
  weight: ["400", "500", "700"],
});

const monoFont = IBM_Plex_Mono({
  subsets: ["latin"],
  variable: "--font-mono",
  weight: ["400", "500"],
});

export const metadata: Metadata = {
  title: "Datium Reporting VoIP",
  description: "Panel visual profesional para trafico mensual y facturacion por reseller.",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="es">
      <body className={`${titleFont.variable} ${monoFont.variable}`}>{children}</body>
    </html>
  );
}
