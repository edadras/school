import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/api.dart';
import '../../core/realtime.dart';
import '../../core/session.dart';
import '../../design/theme.dart';

/// One drawn item. Coordinates are normalised to 0..1 so every screen size renders the same board.
class Shape {
  Shape({required this.type, required this.points, this.color = 0xFF1B2A41, this.width = 3, this.text, this.id});
  final String type; // stroke | line | rect | ellipse | text
  final List<Offset> points;
  final int color;
  final double width;
  final String? text;
  int? id;

  Map<String, dynamic> toPayload() => {
        'type': type == 'text' ? 'text' : (type == 'stroke' ? 'stroke' : 'shape'),
        'data': {'kind': type, 'pts': [for (final p in points) [p.dx, p.dy]], 'color': color, 'w': width, if (text != null) 'text': text},
      };

  static Shape? fromPayload(Map<String, dynamic> p, {int? id}) {
    final d = p['data'];
    if (d is! Map || d['pts'] == null) return null;
    return Shape(type: (d['kind'] ?? p['type'] ?? 'stroke') as String, points: [for (final q in d['pts'] as List) Offset((q[0] as num).toDouble(), (q[1] as num).toDouble())], color: (d['color'] as num?)?.toInt() ?? 0xFF1B2A41, width: (d['w'] as num?)?.toDouble() ?? 3, text: d['text'] as String?, id: id);
  }
}

class BoardPainter extends CustomPainter {
  BoardPainter(this.shapes, this.live);
  final List<Shape> shapes;
  final Shape? live;
  @override
  void paint(Canvas canvas, Size size) {
    canvas.drawRect(Offset.zero & size, Paint()..color = Colors.white);
    for (final s in [...shapes, ?live]) {
      final paint = Paint()..color = Color(s.color)..strokeWidth = s.width..style = PaintingStyle.stroke..strokeCap = StrokeCap.round..strokeJoin = StrokeJoin.round;
      Offset o(Offset p) => Offset(p.dx * size.width, p.dy * size.height);
      if (s.points.isEmpty) continue;
      switch (s.type) {
        case 'stroke':
          final path = Path()..moveTo(o(s.points.first).dx, o(s.points.first).dy);
          for (final p in s.points.skip(1)) { path.lineTo(o(p).dx, o(p).dy); }
          if (s.points.length == 1) {
            canvas.drawCircle(o(s.points.first), s.width / 2, paint..style = PaintingStyle.fill);
          } else {
            canvas.drawPath(path, paint);
          }
        case 'line':
          canvas.drawLine(o(s.points.first), o(s.points.last), paint);
        case 'rect':
          canvas.drawRect(Rect.fromPoints(o(s.points.first), o(s.points.last)), paint);
        case 'ellipse':
          canvas.drawOval(Rect.fromPoints(o(s.points.first), o(s.points.last)), paint);
        case 'text':
          final tp = TextPainter(text: TextSpan(text: s.text, style: TextStyle(color: Color(s.color), fontSize: 14 + s.width * 2, fontFamily: 'Vazirmatn')), textDirection: TextDirection.rtl)..layout(maxWidth: size.width);
          tp.paint(canvas, o(s.points.first));
      }
    }
  }

  @override
  bool shouldRepaint(covariant BoardPainter old) => true;
}

/// Shared whiteboard. The host draws; everyone else watches. Late joiners and reconnects replay persisted events (`since_id`).
class Whiteboard extends ConsumerStatefulWidget {
  const Whiteboard({super.key, required this.sessionId, required this.canDraw});
  final int sessionId;
  final bool canDraw;
  @override
  ConsumerState<Whiteboard> createState() => _WhiteboardState();
}

class _WhiteboardState extends ConsumerState<Whiteboard> {
  final List<Shape> _shapes = [];
  Shape? _live;
  String _tool = 'stroke';
  int _color = 0xFF1B2A41;
  double _width = 3;
  int _lastId = 0;
  final _seen = <int>{};
  Timer? _poll;
  void Function()? _unsub;
  Offset? _start;

  @override
  void initState() {
    super.initState();
    _sync();
    final s = ref.read(sessionProvider);
    _unsub = ref.read(realtimeProvider).subscribe(sessionChannel(s.activeSchoolId!, widget.sessionId), (e, d) {
      if (e == 'whiteboard') _apply(d['id'] as int, Map<String, dynamic>.from(d['payload'] as Map));
    });
    _poll = Timer.periodic(const Duration(seconds: 5), (_) => _sync());
  }

  @override
  void dispose() {
    _poll?.cancel();
    _unsub?.call();
    super.dispose();
  }

  Future<void> _sync() async {
    try {
      final r = await ref.read(apiProvider).get('/sessions/${widget.sessionId}/whiteboard', query: {'since_id': _lastId});
      for (final e in r['data'] as List) {
        _apply(e['id'] as int, Map<String, dynamic>.from(e['payload'] as Map));
      }
    } catch (_) {}
  }

  void _apply(int id, Map<String, dynamic> payload) {
    if (!_seen.add(id)) return; // same event via realtime + REST: draw once
    if (id > _lastId) _lastId = id;
    if (payload['type'] == 'clear') {
      _shapes.removeWhere((s) => (s.id ?? 0) < id);
    } else {
      final s = Shape.fromPayload(payload, id: id);
      if (s != null) {
        final at = _shapes.indexWhere((x) => (x.id ?? 1 << 30) > id);
        at < 0 ? _shapes.add(s) : _shapes.insert(at, s);
      }
    }
    if (mounted) setState(() {});
  }

  Future<void> _push(Map<String, dynamic> payload) async {
    try {
      final r = await ref.read(apiProvider).post('/sessions/${widget.sessionId}/whiteboard', data: payload);
      _apply(r['data']['id'] as int, payload);
    } on ApiException catch (e) {
      if (mounted) setState(() => _live = null);
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.readable)));
    }
  }

  Offset _norm(Offset p, Size size) => Offset((p.dx / size.width).clamp(0, 1), (p.dy / size.height).clamp(0, 1));

  Future<void> _text(Offset at) async {
    final c = TextEditingController();
    final t = await showDialog<String>(context: context, builder: (ctx) => AlertDialog(title: const Text('متن'), content: TextField(controller: c, autofocus: true), actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('انصراف')), FilledButton(onPressed: () => Navigator.pop(ctx, c.text), child: const Text('افزودن'))]));
    if (t == null || t.trim().isEmpty) return;
    _push(Shape(type: 'text', points: [at], color: _color, width: _width, text: t.trim()).toPayload());
  }

  @override
  Widget build(BuildContext context) {
    const colors = [0xFF1B2A41, 0xFFC53030, 0xFF1F6FBF, 0xFF1E8A5A, 0xFFB45309];
    return Column(children: [
      if (widget.canDraw)
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4), color: Colors.white,
          child: SingleChildScrollView(scrollDirection: Axis.horizontal, child: Row(children: [
            for (final t in const [('stroke', Icons.edit, 'قلم'), ('line', Icons.horizontal_rule, 'خط'), ('rect', Icons.crop_square, 'مستطیل'), ('ellipse', Icons.circle_outlined, 'بیضی'), ('text', Icons.text_fields, 'متن'), ('eraser', Icons.cleaning_services_outlined, 'پاک‌کن')])
              IconButton(tooltip: t.$3, isSelected: _tool == t.$1, onPressed: () => setState(() => _tool = t.$1), icon: Icon(t.$2), style: _tool == t.$1 ? IconButton.styleFrom(backgroundColor: Palette.brandSoft) : null),
            const SizedBox(width: 8),
            for (final c in colors) GestureDetector(onTap: () => setState(() => _color = c), child: Container(width: 24, height: 24, margin: const EdgeInsets.symmetric(horizontal: 3), decoration: BoxDecoration(color: Color(c), shape: BoxShape.circle, border: Border.all(color: _color == c ? Palette.brand : Colors.transparent, width: 3)))),
            SizedBox(width: 110, child: Slider(value: _width, min: 1, max: 12, onChanged: (v) => setState(() => _width = v))),
            IconButton(tooltip: 'پاک‌کردن همه', onPressed: () { _push({'type': 'clear'}); }, icon: const Icon(Icons.delete_sweep_outlined)),
          ])),
        ),
      Expanded(
        child: LayoutBuilder(builder: (context, c) {
          final size = Size(c.maxWidth, c.maxHeight);
          return GestureDetector(
            behavior: HitTestBehavior.opaque,
            onPanStart: widget.canDraw && _tool != 'text' ? (d) { _start = _norm(d.localPosition, size); setState(() => _live = Shape(type: _tool == 'eraser' ? 'stroke' : _tool, points: [_start!], color: _tool == 'eraser' ? 0xFFFFFFFF : _color, width: _tool == 'eraser' ? 18 : _width)); } : null,
            onPanUpdate: widget.canDraw && _tool != 'text' ? (d) => setState(() {
                  final p = _norm(d.localPosition, size);
                  if (_live!.type == 'stroke') { _live!.points.add(p); } else { _live = Shape(type: _live!.type, points: [_start!, p], color: _live!.color, width: _live!.width); }
                }) : null,
            onPanEnd: widget.canDraw && _tool != 'text' ? (_) { final s = _live!; _push(s.toPayload()).then((_) => mounted ? setState(() => _live = null) : null); } : null,
            onTapUp: widget.canDraw && _tool == 'text' ? (d) => _text(_norm(d.localPosition, size)) : null,
            child: ClipRect(child: CustomPaint(size: size, painter: BoardPainter(_shapes, _live))),
          );
        }),
      ),
    ]);
  }
}
