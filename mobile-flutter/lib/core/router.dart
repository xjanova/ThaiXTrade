/// TPIX TRADE — App Router
/// go_router + StatefulShellRoute. Tab bar: Home · AI · Market · Swap · Wallet.
/// Trade lives in the Market branch (tab bar stays, center active).
/// Settings & Profile are pushed detail routes (back chevron).
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
import '../screens/ai_trade/ai_trade_screen.dart';
import '../screens/swap/swap_screen.dart';
import '../screens/profile/profile_screen.dart';
import '../screens/bridge/bridge_screen.dart';
import '../services/deep_link_service.dart';

final GlobalKey<NavigatorState> rootNavigatorKey = GlobalKey<NavigatorState>();

final GoRouter appRouter = GoRouter(
  navigatorKey: rootNavigatorKey,
  initialLocation: '/splash',
  redirect: (context, state) {
    // Deep-link fallback — Android intent ส่ง tpixtrade://connect?address=...
    // Flutter framework parse แล้ว host หาย เหลือแค่ path "/" + query
    // → ส่งให้ DeepLinkService infer host จาก query keys + handle เอง
    // → redirect ไป /splash ไม่ให้ router throw "no routes for location"
    final uri = state.uri;
    if (uri.path == '/' && uri.queryParameters.isNotEmpty) {
      DeepLinkService().handleRouterFallback(uri);
      return '/splash';
    }
    return null;
  },
  routes: [
    // Splash screen
    GoRoute(
      path: '/splash',
      builder: (context, state) => const SplashScreen(),
    ),

    // Main shell with floating bottom navigation
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

        // Tab 1: AI Trade
        StatefulShellBranch(
          routes: [
            GoRoute(
              path: '/ai',
              builder: (context, state) => const AiTradeScreen(),
            ),
          ],
        ),

        // Tab 2: Market (center button). Trade lives in this branch so the
        // floating tab bar stays visible and the center stays active.
        StatefulShellBranch(
          routes: [
            GoRoute(
              path: '/markets',
              builder: (context, state) => const MarketsScreen(),
            ),
            GoRoute(
              path: '/trade',
              builder: (context, state) => const TradeScreen(),
            ),
          ],
        ),

        // Tab 3: Swap
        StatefulShellBranch(
          routes: [
            GoRoute(
              path: '/swap',
              builder: (context, state) => const SwapScreen(),
            ),
          ],
        ),

        // Tab 4: Wallet (Portfolio)
        StatefulShellBranch(
          routes: [
            GoRoute(
              path: '/portfolio',
              builder: (context, state) => const PortfolioScreen(),
            ),
          ],
        ),
      ],
    ),

    // ── Detail / full-screen routes (pushed over the shell) ──
    GoRoute(
      path: '/settings',
      parentNavigatorKey: rootNavigatorKey,
      builder: (context, state) => const SettingsScreen(),
    ),
    GoRoute(
      path: '/profile',
      parentNavigatorKey: rootNavigatorKey,
      builder: (context, state) => const ProfileScreen(),
    ),
    GoRoute(
      path: '/bridge',
      parentNavigatorKey: rootNavigatorKey,
      builder: (context, state) => const BridgeScreen(),
    ),
  ],
);
