# TPIX TRADE - Mobile App

Native mobile trading app for iOS and Android, built with React Native + Expo.

## Tech Stack

- **Framework**: React Native 0.76 + Expo SDK 52
- **Navigation**: Expo Router (file-based routing)
- **State Management**: Zustand
- **Styling**: React Native StyleSheet + Glass Morphism design system
- **Charts**: React Native SVG
- **Animations**: React Native Reanimated
- **Icons**: @expo/vector-icons (Ionicons)

## Getting Started

### Prerequisites

- Node.js 20+
- npm or yarn
- Expo CLI: `npm install -g expo-cli`
- Android Studio (for APK build) or Xcode (for iOS)

### Install & Run

```bash
cd mobile
npm install

# Start development server
npx expo start

# Run on Android emulator
npx expo run:android

# Run on iOS simulator (macOS only)
npx expo run:ios
```

### Build APK

```bash
# Generate native Android project
npx expo prebuild --platform android

# Build release APK
cd android && ./gradlew assembleRelease
```

The APK will be at: `android/app/build/outputs/apk/release/app-release.apk`

### Generate App Icons

```bash
# Requires ImageMagick
bash scripts/generate-assets.sh
```

Or replace the placeholder images in `assets/images/` with your own:
- `icon.png` - 1024x1024 App icon
- `adaptive-icon.png` - 1024x1024 Android adaptive icon
- `splash.png` - 1284x2778 Splash screen
- `favicon.png` - 48x48 Web favicon

## Project Structure

```
mobile/
├── app/                    # Expo Router screens
│   ├── _layout.tsx         # Root layout (StatusBar, theme)
│   └── (tabs)/             # Bottom tab navigation
│       ├── _layout.tsx     # Tab bar configuration
│       ├── index.tsx       # Home / Dashboard
│       ├── markets.tsx     # Market listings
│       ├── trade.tsx       # Trading interface
│       ├── portfolio.tsx   # Portfolio & history
│       └── settings.tsx    # App settings
├── components/
│   ├── common/             # Reusable UI components
│   │   ├── GlassCard.tsx
│   │   ├── GradientButton.tsx
│   │   ├── PriceChange.tsx
│   │   ├── Header.tsx
│   │   └── SearchBar.tsx
│   ├── trading/            # Trading-specific components
│   │   ├── MiniChart.tsx
│   │   ├── OrderBookMobile.tsx
│   │   ├── TradeFormMobile.tsx
│   │   └── PairHeader.tsx
│   ├── markets/
│   │   └── MarketRow.tsx
│   └── portfolio/
│       └── AssetRow.tsx
├── theme/                  # Design tokens
│   ├── colors.ts
│   ├── spacing.ts
│   └── typography.ts
├── stores/                 # Zustand state management
│   ├── marketStore.ts
│   └── portfolioStore.ts
├── services/
│   └── api.ts             # API client
└── assets/
    └── images/             # App icons & splash
```

## Screens

| Screen | Description |
|--------|-------------|
| **Home** | Portfolio value, quick actions, favorites, top gainers |
| **Markets** | Searchable list with filters, sparkline charts |
| **Trade** | Price chart, order book, trade form, recent trades |
| **Portfolio** | Allocation chart, asset list, transaction history |
| **Settings** | Wallet, security, trading prefs, notifications |

## Design System

- **Theme**: Glass Morphism Dark
- **Primary**: Cyan (#06b6d4)
- **Accent**: Purple (#8b5cf6)
- **Trading Green**: #00C853
- **Trading Red**: #FF1744
- **Background**: #0a0e1a

## CI/CD

GitHub Actions automatically builds the APK when changes are pushed to `mobile/`.
Download the APK from the Actions artifacts or GitHub Releases.
