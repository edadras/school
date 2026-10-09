import 'package:flutter/material.dart';
import '../../design/theme.dart';
import '../live/whiteboard.dart';

/// Offline drawing pad for assignment answers. Output is the same normalised-shape JSON the whiteboard uses.
class DrawingPad extends StatefulWidget {
  const DrawingPad({super.key, required this.initial, required this.onChanged, this.height = 320});
  final List<dynamic>? initial;
  final ValueChanged<List<Map<String, dynamic>>> onChanged;
  final double height;
  @override
  State<DrawingPad> createState() => _DrawingPadState();
}

class _DrawingPadState extends State<DrawingPad> {
  final List<Shape> _shapes = [];
  Shape? _live;
  int _color = 0xFF1B2A41;
  double _w = 3;
  bool _erase = false;

  @override
  void initState() {
    super.initState();
    for (final j in widget.initial ?? const []) {
      final s = Shape.fromPayload({'type': 'stroke', 'data': j});
      if (s != null) _shapes.add(s);
    }
  }

  void _emit() => widget.onChanged([for (final s in _shapes) s.toPayload()['data'] as Map<String, dynamic>]);

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          for (final c in const [0xFF1B2A41, 0xFFC53030, 0xFF1F6FBF, 0xFF1E8A5A]) GestureDetector(onTap: () => setState(() { _color = c; _erase = false; }), child: Container(width: 26, height: 26, margin: const EdgeInsets.all(4), decoration: BoxDecoration(color: Color(c), shape: BoxShape.circle, border: Border.all(color: !_erase && _color == c ? Palette.brand : Colors.transparent, width: 3)))),
          IconButton(tooltip: 'پاک‌کن', isSelected: _erase, onPressed: () => setState(() => _erase = !_erase), icon: const Icon(Icons.cleaning_services_outlined)),
          IconButton(tooltip: 'برگرداندن', onPressed: _shapes.isEmpty ? null : () { setState(_shapes.removeLast); _emit(); }, icon: const Icon(Icons.undo)),
          IconButton(tooltip: 'پاک‌کردن همه', onPressed: _shapes.isEmpty ? null : () { setState(_shapes.clear); _emit(); }, icon: const Icon(Icons.delete_outline)),
          Expanded(child: Slider(value: _w, min: 1, max: 10, onChanged: (v) => setState(() => _w = v))),
        ]),
        Container(
          height: widget.height, decoration: BoxDecoration(border: Border.all(color: Palette.border), borderRadius: BorderRadius.circular(12)), clipBehavior: Clip.antiAlias,
          child: LayoutBuilder(builder: (context, c) {
            final size = Size(c.maxWidth, c.maxHeight);
            Offset n(Offset p) => Offset((p.dx / size.width).clamp(0, 1), (p.dy / size.height).clamp(0, 1));
            return GestureDetector(
              onPanStart: (d) => setState(() => _live = Shape(type: 'stroke', points: [n(d.localPosition)], color: _erase ? 0xFFFFFFFF : _color, width: _erase ? 16 : _w)),
              onPanUpdate: (d) => setState(() => _live!.points.add(n(d.localPosition))),
              onPanEnd: (_) { setState(() { _shapes.add(_live!); _live = null; }); _emit(); },
              child: CustomPaint(size: size, painter: BoardPainter(_shapes, _live)),
            );
          }),
        ),
      ]);
}

/// Symbol palette for math answers (no heavy formula editor needed for school levels).
class MathPad extends StatelessWidget {
  const MathPad({super.key, required this.controller, required this.onChanged});
  final TextEditingController controller;
  final VoidCallback onChanged;
  static const symbols = ['+', '−', '×', '÷', '=', '≠', '≤', '≥', '²', '³', '√', 'π', '∞', '∑', '∫', '°', '½', '⅓', '¼', '(', ')', '^', '/', 'α', 'β', 'θ', '∠', '△', '|x|', '%'];
  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Wrap(spacing: 4, runSpacing: 4, children: [
          for (final s in symbols)
            InkWell(
              borderRadius: BorderRadius.circular(8),
              onTap: () {
                final sel = controller.selection;
                final t = controller.text;
                final at = sel.isValid ? sel.start : t.length;
                controller.value = TextEditingValue(text: t.replaceRange(at, sel.isValid ? sel.end : at, s), selection: TextSelection.collapsed(offset: at + s.length));
                onChanged();
              },
              child: Container(width: 40, height: 38, alignment: Alignment.center, decoration: BoxDecoration(color: Palette.brandSoft, borderRadius: BorderRadius.circular(8)), child: Text(s, style: const TextStyle(fontSize: 17, color: Palette.brand))),
            ),
        ]),
        const SizedBox(height: 8),
        TextField(controller: controller, minLines: 3, maxLines: 8, textDirection: TextDirection.ltr, onChanged: (_) => onChanged(), decoration: const InputDecoration(hintText: 'مراحل حل را با نمادهای بالا بنویسید...')),
      ]);
}
