import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_web_plugins/url_strategy.dart';
import 'package:go_router/go_router.dart';
import 'app.dart';

void main() {
  GoRouter.optionURLReflectsImperativeAPIs = true; // pushed screens (exam, live class) keep their own URL so a refresh resumes them
  usePathUrlStrategy(); // clean URLs (/admin/people) — the web server must fall back to index.html (see docs/deployment)
  runApp(const ProviderScope(child: SchoolApp()));
}
