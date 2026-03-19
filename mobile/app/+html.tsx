/**
 * Custom HTML template for Expo Web
 * เทมเพลต HTML สำหรับ Expo Web
 *
 * Adds proper meta tags, viewport, theme color, and global web styles.
 * เพิ่ม meta tags, viewport, สีธีม และ global styles สำหรับเว็บ
 */

import { ScrollViewStyleReset } from 'expo-router/html';

export default function Root({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <head>
        <meta charSet="utf-8" />
        <meta httpEquiv="X-UA-Compatible" content="IE=edge" />

        {/* Viewport / การตั้งค่า viewport */}
        <meta
          name="viewport"
          content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover"
        />

        {/* Theme & PWA / ธีมและ PWA */}
        <meta name="theme-color" content="#0a0e1a" />
        <meta name="background-color" content="#0a0e1a" />
        <meta name="apple-mobile-web-app-capable" content="yes" />
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
        <meta name="apple-mobile-web-app-title" content="TPIX TRADE" />

        {/* SEO / การค้นหา */}
        <meta name="description" content="Trade crypto with zero fees on TPIX TRADE DEX - Decentralized Exchange by Xman Studio" />
        <meta name="application-name" content="TPIX TRADE" />

        {/* Open Graph / ข้อมูลแชร์ */}
        <meta property="og:title" content="TPIX TRADE - Decentralized Exchange" />
        <meta property="og:description" content="Trade crypto with zero fees on TPIX TRADE DEX" />
        <meta property="og:type" content="website" />

        <title>TPIX TRADE</title>

        {/* Expo scroll reset / รีเซ็ต scroll ของ Expo */}
        <ScrollViewStyleReset />

        {/* Global web styles / สไตล์ global สำหรับเว็บ */}
        <style dangerouslySetInnerHTML={{ __html: globalStyles }} />
      </head>
      <body>{children}</body>
    </html>
  );
}

const globalStyles = `
/* Base reset / รีเซ็ตพื้นฐาน */
html, body, #root {
  margin: 0;
  padding: 0;
  height: 100%;
  overflow: hidden;
  background-color: #0a0e1a;
  color: #ffffff;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

/* Custom scrollbar for dark theme / Scrollbar สำหรับ dark theme */
::-webkit-scrollbar {
  width: 6px;
  height: 6px;
}
::-webkit-scrollbar-track {
  background: transparent;
}
::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.15);
  border-radius: 3px;
}
::-webkit-scrollbar-thumb:hover {
  background: rgba(255, 255, 255, 0.25);
}

/* Disable blue highlight on tap / ปิด highlight สีฟ้าตอนแตะ */
* {
  -webkit-tap-highlight-color: transparent;
}

/* Selection color / สีเวลาเลือกข้อความ */
::selection {
  background: rgba(6, 182, 212, 0.3);
  color: #ffffff;
}

/* Focus outline for accessibility / outline ตอน focus สำหรับ accessibility */
:focus-visible {
  outline: 2px solid #06b6d4;
  outline-offset: 2px;
}

/* Smooth scrolling / scroll แบบ smooth */
@media (prefers-reduced-motion: no-preference) {
  html {
    scroll-behavior: smooth;
  }
}

/* Input styles for web / สไตล์ input สำหรับเว็บ */
input, textarea {
  background-color: transparent;
  border: none;
  outline: none;
  color: inherit;
  font-family: inherit;
}

input:focus, textarea:focus {
  outline: none;
}

/* Pressable hover effect on web / เอฟเฟกต์ hover สำหรับ Pressable บนเว็บ */
[role="button"]:hover {
  opacity: 0.85;
}

/* Switch web compatibility / Switch สำหรับเว็บ */
input[type="checkbox"] {
  cursor: pointer;
}
`;
