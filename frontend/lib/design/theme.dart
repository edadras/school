import 'package:flutter/cupertino.dart' show CupertinoPageTransitionsBuilder;
import 'package:flutter/material.dart';

/// Design tokens. White surfaces, soft blue accents, blue-grey background; contrast checked ≥ 4.5:1 for text.
class Palette {
  static const brand = Color(0xFF1F6FBF);
  static const brandSoft = Color(0xFFE8F1FB);
  static const brandMid = Color(0xFF8DB9E8);
  static const bg = Color(0xFFF5F8FC);
  static const surface = Colors.white;
  static const border = Color(0xFFE2EAF4);
  static const text = Color(0xFF1B2A41);
  static const muted = Color(0xFF5A6E88);
  static const success = Color(0xFF1E8A5A);
  static const successSoft = Color(0xFFE6F5EE);
  static const warn = Color(0xFF9A6700);
  static const warnSoft = Color(0xFFFFF4DB);
  static const danger = Color(0xFFC53030);
  static const dangerSoft = Color(0xFFFDEBEB);
}

class Gaps {
  static const xs = 4.0, sm = 8.0, md = 16.0, lg = 24.0, xl = 32.0;
}

ThemeData buildTheme() {
  final scheme = ColorScheme.fromSeed(seedColor: Palette.brand, brightness: Brightness.light).copyWith(
    primary: Palette.brand, onPrimary: Colors.white, surface: Palette.surface, onSurface: Palette.text, secondary: Palette.brandMid,
    primaryContainer: Palette.brandSoft, onPrimaryContainer: Palette.brand, error: Palette.danger, outline: Palette.border, surfaceTint: Colors.transparent,
  );
  const radius = 14.0;
  OutlineInputBorder border(Color c) => OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: c));
  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    fontFamily: 'Vazirmatn',
    scaffoldBackgroundColor: Palette.bg,
    dividerColor: Palette.border,
    textTheme: const TextTheme(
      headlineSmall: TextStyle(fontWeight: FontWeight.w700, fontSize: 22, color: Palette.text, height: 1.5),
      titleLarge: TextStyle(fontWeight: FontWeight.w700, fontSize: 18, color: Palette.text, height: 1.5),
      titleMedium: TextStyle(fontWeight: FontWeight.w500, fontSize: 16, color: Palette.text, height: 1.5),
      bodyLarge: TextStyle(fontSize: 15, color: Palette.text, height: 1.7),
      bodyMedium: TextStyle(fontSize: 14, color: Palette.text, height: 1.7),
      bodySmall: TextStyle(fontSize: 12.5, color: Palette.muted, height: 1.6),
      labelLarge: TextStyle(fontWeight: FontWeight.w500, fontSize: 14),
    ),
    appBarTheme: const AppBarTheme(backgroundColor: Palette.surface, foregroundColor: Palette.text, elevation: 0, scrolledUnderElevation: 0, centerTitle: false,
        shape: Border(bottom: BorderSide(color: Palette.border)), titleTextStyle: TextStyle(fontFamily: 'Vazirmatn', fontWeight: FontWeight.w700, fontSize: 17, color: Palette.text)),
    cardTheme: CardThemeData(color: Palette.surface, elevation: 0, margin: EdgeInsets.zero, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(radius), side: const BorderSide(color: Palette.border))),
    inputDecorationTheme: InputDecorationTheme(
      filled: true, fillColor: Palette.surface, contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      border: border(Palette.border), enabledBorder: border(Palette.border), focusedBorder: border(Palette.brand), errorBorder: border(Palette.danger), focusedErrorBorder: border(Palette.danger),
      labelStyle: const TextStyle(color: Palette.muted), floatingLabelStyle: const TextStyle(color: Palette.brand),
    ),
    filledButtonTheme: FilledButtonThemeData(style: FilledButton.styleFrom(minimumSize: const Size(48, 46), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)), textStyle: const TextStyle(fontFamily: 'Vazirmatn', fontWeight: FontWeight.w500))),
    outlinedButtonTheme: OutlinedButtonThemeData(style: OutlinedButton.styleFrom(minimumSize: const Size(48, 46), side: const BorderSide(color: Palette.border), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)), textStyle: const TextStyle(fontFamily: 'Vazirmatn'))),
    textButtonTheme: TextButtonThemeData(style: TextButton.styleFrom(textStyle: const TextStyle(fontFamily: 'Vazirmatn', fontWeight: FontWeight.w500))),
    navigationRailTheme: const NavigationRailThemeData(backgroundColor: Palette.surface, indicatorColor: Palette.brandSoft, selectedIconTheme: IconThemeData(color: Palette.brand), selectedLabelTextStyle: TextStyle(fontFamily: 'Vazirmatn', color: Palette.brand, fontWeight: FontWeight.w700), unselectedLabelTextStyle: TextStyle(fontFamily: 'Vazirmatn', color: Palette.muted)),
    navigationBarTheme: NavigationBarThemeData(backgroundColor: Palette.surface, indicatorColor: Palette.brandSoft, surfaceTintColor: Colors.transparent, labelTextStyle: WidgetStatePropertyAll(const TextStyle(fontFamily: 'Vazirmatn', fontSize: 11.5))),
    chipTheme: ChipThemeData(backgroundColor: Palette.brandSoft, side: BorderSide.none, labelStyle: const TextStyle(fontFamily: 'Vazirmatn', fontSize: 12.5, color: Palette.text), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20))),
    dialogTheme: DialogThemeData(backgroundColor: Palette.surface, surfaceTintColor: Colors.transparent, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16))),
    snackBarTheme: SnackBarThemeData(behavior: SnackBarBehavior.floating, backgroundColor: Palette.text, contentTextStyle: const TextStyle(fontFamily: 'Vazirmatn', color: Colors.white), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12))),
    tabBarTheme: const TabBarThemeData(labelColor: Palette.brand, unselectedLabelColor: Palette.muted, indicatorColor: Palette.brand, dividerColor: Palette.border, labelStyle: TextStyle(fontFamily: 'Vazirmatn', fontWeight: FontWeight.w700), unselectedLabelStyle: TextStyle(fontFamily: 'Vazirmatn')),
    pageTransitionsTheme: PageTransitionsTheme(builders: const {
      TargetPlatform.android: FadeForwardsPageTransitionsBuilder(), TargetPlatform.iOS: CupertinoPageTransitionsBuilder(), TargetPlatform.linux: FadeForwardsPageTransitionsBuilder(), TargetPlatform.windows: FadeForwardsPageTransitionsBuilder(), TargetPlatform.macOS: CupertinoPageTransitionsBuilder(),
    }),
  );
}
