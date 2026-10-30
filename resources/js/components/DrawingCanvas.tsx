import { useEffect, useRef, type PointerEvent } from 'react';
import type { Chunk, Point } from '../types';

export function paint(ctx: CanvasRenderingContext2D, chunk: Pick<Chunk, 'points' | 'color' | 'width'>) {
  if (!chunk.points.length) return;
  ctx.strokeStyle = chunk.color;
  ctx.fillStyle = chunk.color;
  ctx.lineWidth = chunk.width;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  ctx.beginPath();
  ctx.moveTo(...chunk.points[0]);
  if (chunk.points.length === 1) {
    ctx.arc(...chunk.points[0], chunk.width / 2, 0, Math.PI * 2);
    ctx.fill();
  } else {
    chunk.points.slice(1).forEach(p => ctx.lineTo(...p));
    ctx.stroke();
  }
}

export default function DrawingCanvas({
  chunks,
  enabled,
  color,
  width,
  onChunk
}: {
  chunks: Chunk[];
  enabled: boolean;
  color: string;
  width: number;
  onChunk: (chunk: Chunk) => void;
}) {
  const canvas = useRef<HTMLCanvasElement>(null);
  const active = useRef(false);
  const points = useRef<Point[]>([]);
  const style = useRef({
    color,
    width
  });
  const callback = useRef(onChunk);
  callback.current = onChunk;
  const redraw = () => {
    const ctx = canvas.current?.getContext('2d');
    if (!ctx) return;
    ctx.clearRect(0, 0, 1000, 700);
    chunks.forEach(c => paint(ctx, c));
    paint(ctx, {
      ...style.current,
      points: points.current
    });
  };
  useEffect(redraw, [chunks]);
  const flush = (end = false) => {
    if (!points.current.length) return;
    const batch = points.current;
    callback.current({
      id: crypto.randomUUID(),
      ...style.current,
      points: batch
    });
    points.current = end ? [] : [batch[batch.length - 1]];
  };
  useEffect(() => {
    const timer = setInterval(() => {
      if (active.current && points.current.length > 1) flush();
    }, 100);
    return () => clearInterval(timer);
  }, []);
  useEffect(() => {
    if (!enabled) {
      active.current = false;
      points.current = [];
    }
  }, [enabled]);
  const point = (event: PointerEvent<HTMLCanvasElement>): Point => {
    const bounds = event.currentTarget.getBoundingClientRect();
    const x = (event.clientX - bounds.left) / bounds.width * 1000;
    const y = (event.clientY - bounds.top) / bounds.height * 700;
    return [
      Math.round(Math.max(0, Math.min(1000, x))),
      Math.round(Math.max(0, Math.min(700, y)))
    ];
  };
  const finish = () => {
    if (!active.current) return;
    active.current = false;
    flush(true);
  };
  const handlePointerDown = (event: PointerEvent<HTMLCanvasElement>) => {
    if (!enabled || event.button !== 0) return;
    event.currentTarget.setPointerCapture(event.pointerId);
    active.current = true;
    style.current = { color, width };
    points.current = [point(event)];
    const ctx = canvas.current!.getContext('2d')!;
    paint(ctx, {
      ...style.current,
      points: points.current
    });
  };

  const handlePointerMove = (event: PointerEvent<HTMLCanvasElement>) => {
    if (!enabled || !active.current) return;
    const p = point(event);
    const previous = points.current[points.current.length - 1];
    if (previous && Math.hypot(p[0] - previous[0], p[1] - previous[1]) < 2) return;
    points.current.push(p);
    paint(canvas.current!.getContext('2d')!, {
      ...style.current,
      points: previous ? [previous, p] : [p]
    });
    if (points.current.length >= 40) flush();
  };

  return (
    <canvas
      ref={canvas}
      width={1000}
      height={700}
      aria-label={enabled ? 'Área de desenho' : 'Desenho do parceiro'}
      className={enabled ? 'drawable' : ''}
      onPointerDown={handlePointerDown}
      onPointerMove={handlePointerMove}
      onPointerUp={finish}
      onPointerCancel={finish}
      onLostPointerCapture={finish}
    />
  );
}
