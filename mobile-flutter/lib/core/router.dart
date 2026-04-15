/// TPIX TRADE — App Router
/// go_router + StatefulShellRoute สำหรับ 5 tabs
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../screens/shell_screen.dart';
import '../screens/splash_screen.dart';
import '../screens/home/home_screen.dart';
import '../screens/markets/markets_screen.dart';
import '../screens/trade/trade_screen.dart';
import '../screens/portfolio/portfolio_screen.dart';
import '../screens/settings/settings_screen.dart';
import '../screens/bridge/bridge_screen.dart';

final GlobalKey<NavigatorState> rootNavigatorKey = GlobalKey<NavigatorState>();

final GoRouter appRouter = GoRouter(
  navigatorKey: rootNavigatorKey,
  initialLocation: '/splash',
  routes: [
    // Splash screen
    GoRoute(
      path: '/splash',
      builder: (context, state) => const SplashScreen(),
    ),

    // Main shell with bottom navigation
    StatefulShellRoute.indexedStack(
      builder: (context, state, navigationShell) =>
          ShellScreen(navigationShell: navigationShell),
      branches: [
        // Tab 0: Home
        StatefulShellBranch(
          routes: [
            GoRoute(
              path: '/home',
              builder: (context, state) => const HomeScreen(),
            ),
          ],
        ),

        // Tab 1: Markets
        StatefulShellBranch(
          routes: [
            GoRoute(
              path: '/markets',
              builder: (context, state) => const MarketsScreen(),
            ),
          ],
        ),

        // Tab 2: Trade
        StatefulShellBranch(
          routes: [
            GoRoute(
              path: '/trade',
              builder: (context, state) => const TradeScreen(),
            ),
          ],
        ),

        // Tab 3: Portfolio
        StatefulShellBranch(
          routes: [
            GoRoute(
              path: '/portfolio',
              builder: (context, state) => const PortfolioScreen(),
            ),
          ],
        ),

        // Tab 4: Settings
        StatefulShellBranch(
          routes: [
            GoRoute(
              path: '/settings',
              builder: (context, state) => const SettingsScreen(),
            ),
          ],
        ),
      ],
    ),

    // Bridge (full screen, ไม่อยู่ใน bottom nav)
    GoRoute(
      path: '/bridge',
      parentNavigatorKey: rootNavigatorKey,
      builder: (context, state) => const BridgeScreen(),
    ),
  ],
);
