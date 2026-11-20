// Copied from heptapod.studio/mark/src/player.ts by scripts/sync-mark-errors.mjs. Do not edit here.
// A dependency-free player for Heptapod mark sequences.
//
// - Plays a sequence on an inline SVG whose seven rects carry data-seg="a" … "g",
//   with one Web Animation per segment, on opacity and fill only.
// - Interruptible: a new sequence starts from whatever the mark shows right now; if that
//   is not the resting H, the mark settles to it first instead of jumping.
// - Display sequences hold a state (a glyph, a number) until release() plays their exit.
// - Pauses while off-screen or while the tab is hidden. Under prefers-reduced-motion it
//   plays nothing: the still H stays, and a held state is shown still, without motion,
//   because it carries information.
// - The hover "step apart" is the only movement and a separate interaction (stepApart).

import {
  EASINGS,
  GEOMETRY,
  PALETTE,
  PILL_RADIUS,
  SEGMENTS,
  STEP_APART,
  frameLook,
  restLook,
  type Colour,
  type Frame,
  type Segment,
  type Sequence,
} from './format';

/** One WAAPI keyframe of a compiled track. Only opacity and fill are ever animated. */
export interface MarkKeyframe {
  offset: number;
  easing: string;
  opacity: number;
  fill: string;
}

/** What one segment shows, with its colour resolved. */
export interface Look {
  opacity: number;
  fill: string;
}

export interface CompiledFrames {
  /** Length of one iteration in ms, including the rest gap. */
  duration: number;
  tracks: Record<Segment, MarkKeyframe[]>;
}

export interface CompileOptions {
  /** Colour values per token; bone usually resolves to the SVG's currentColor. */
  palette?: Partial<Record<Colour, string>>;
  /** Playback speed factor. Default 1. */
  tempo?: number;
  /** Time held on the final state after the last frame, in ms (lets a one-shot breathe between loops). */
  gap?: number;
  /** Where each segment starts. Default: the resting H. */
  from?: Record<Segment, Look>;
}

function resolvedPalette(palette: Partial<Record<Colour, string>> = {}): Record<Colour, string> {
  const resolved = { ...PALETTE, ...palette };
  for (const [token, value] of Object.entries(resolved))
    if (!value) throw new Error(`compile: colour token ${token} has no value; an empty fill would make the browser interpolate across frames`);
  return resolved;
}

/** The look of every segment at the end of a frame. */
export function frameLooks(frame: Frame, palette?: Partial<Record<Colour, string>>): Record<Segment, Look> {
  const colours = resolvedPalette(palette);
  return Object.fromEntries(
    SEGMENTS.map((segment) => {
      const look = frameLook(frame, segment);
      return [segment, { opacity: look.opacity, fill: colours[look.colour] }];
    }),
  ) as Record<Segment, Look>;
}

export function restLooks(palette?: Partial<Record<Colour, string>>): Record<Segment, Look> {
  return frameLooks({ ms: 16, ease: 'step' }, palette);
}

/** Turns a list of frames into per-segment keyframe tracks of equal length. Pure; no DOM. */
export function compileFrames(frames: readonly Frame[], options: CompileOptions = {}): CompiledFrames {
  const palette = resolvedPalette(options.palette);
  const speed = options.tempo ?? 1;
  const gap = options.gap ?? 0;
  const duration = frames.reduce((sum, frame) => sum + frame.ms / speed, 0) + gap;
  const from = options.from ?? restLooks(palette);
  const tracks = {} as Record<Segment, MarkKeyframe[]>;

  for (const segment of SEGMENTS) {
    const points: { at: number; look: Look; easing: string }[] = [];
    let previous = from[segment];
    let frameStart = 0;
    for (const frame of frames) {
      const state = frame.segments?.[segment];
      const end = frameLook(frame, segment);
      const look = { opacity: end.opacity, fill: palette[end.colour] };
      const delay = (state?.delay ?? 0) / speed;
      const transition = (state?.transition ?? frame.transition ?? frame.ms) / speed;
      const easing = EASINGS[state?.ease ?? frame.ease];
      points.push({ at: frameStart, look: previous, easing: 'linear' });
      points.push({ at: frameStart + delay, look: previous, easing });
      points.push({ at: frameStart + delay + transition, look, easing: 'linear' });
      frameStart += frame.ms / speed;
      previous = look;
    }
    points.push({ at: duration, look: previous, easing: 'linear' });

    // Points at the same time hold the same value (every transition is longer than 0),
    // so keep the later one: it carries the easing of the interval that follows.
    const merged = points.filter((point, index) => index === points.length - 1 || (points[index + 1]?.at ?? point.at) - point.at > 1e-9);
    tracks[segment] = merged.map((point) => ({
      offset: Math.min(1, point.at / duration),
      easing: point.easing,
      opacity: point.look.opacity,
      fill: point.look.fill,
    }));
  }
  return { duration, tracks };
}

/** Compiles a sequence's frames (for a holding sequence: its entry into the held state). */
export function compile(sequence: Sequence, options: Omit<CompileOptions, 'from'> = {}): CompiledFrames {
  return compileFrames(sequence.frames, { tempo: sequence.tempo, ...options });
}

export interface PlayOptions {
  /** Repeat until something else plays. Default: the sequence's own loop flag. Ignored for holding sequences. */
  loop?: boolean;
  /** Overrides the sequence's tempo. */
  tempo?: number;
  /** Rest after each lap of a repeating one-shot, in ms. */
  gap?: number;
}

/** How long the mark takes to settle to the resting H when a sequence is interrupted. */
const SETTLE_MS = 200;
const SETTLE_EASING = EASINGS.out;

export class MarkPlayer {
  readonly svg: SVGSVGElement;
  #rects: Record<Segment, SVGGraphicsElement>;
  #palette: Partial<Record<Colour, string>>;
  #animations: Animation[] = [];
  #held: Sequence | null = null;
  #generation = 0;
  #rate = 1;
  #onScreen = true;
  #reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
  #observer: IntersectionObserver;
  #onVisibility = () => this.#syncPaused();
  #onReducedMotion = () => {
    if (!this.#reducedMotion.matches) return;
    this.#cancel();
    this.stepApart(false);
    if (this.#held) this.#showStill(frameLooks(this.#held.frames.at(-1)!, this.#resolvedPalette()));
  };

  constructor(svg: SVGSVGElement, options: { palette?: Partial<Record<Colour, string>> } = {}) {
    this.svg = svg;
    this.#palette = options.palette ?? {};
    const rects = {} as Record<Segment, SVGGraphicsElement>;
    for (const segment of SEGMENTS) {
      const rect = svg.querySelector<SVGGraphicsElement>(`[data-seg="${segment}"]`);
      if (!rect) throw new Error(`MarkPlayer: the SVG has no element with data-seg="${segment}"`);
      rects[segment] = rect;
    }
    this.#rects = rects;
    this.#observer = new IntersectionObserver((entries) => {
      this.#onScreen = entries.some((entry) => entry.isIntersecting);
      this.#syncPaused();
    });
    this.#observer.observe(svg);
    document.addEventListener('visibilitychange', this.#onVisibility);
    this.#reducedMotion.addEventListener('change', this.#onReducedMotion);
  }

  /**
   * Plays a sequence from its first frame, taking over from anything already playing.
   * A one-shot resolves true when it finishes; a holding sequence resolves true when it
   * reaches its held state. Resolves false when interrupted or skipped.
   */
  async play(sequence: Sequence, options: PlayOptions = {}): Promise<boolean> {
    const generation = ++this.#generation;
    this.#held = sequence.hold ? sequence : null;
    if (this.#reducedMotion.matches) {
      this.#cancel();
      if (sequence.hold) this.#showStill(frameLooks(sequence.frames.at(-1)!, this.#resolvedPalette()));
      return Promise.resolve(false);
    }
    const palette = this.#resolvedPalette();
    const tempo = options.tempo ?? sequence.tempo;
    const compiled = compileFrames(sequence.frames, { palette, tempo, gap: sequence.hold ? 0 : (options.gap ?? 0) });
    const loop = !sequence.hold && (options.loop ?? sequence.loop);
    return this.#settle(generation).then((settled) => {
      if (!settled) return false;
      const entry = this.#start(compiled, { iterations: loop ? Infinity : 1, fill: sequence.hold ? 'forwards' : 'none' });
      return this.#finished(entry, generation).then((finished) => {
        if (!finished) return false;
        const lap = sequence.hold?.loop;
        if (lap) {
          // Start the lap before dropping the entry, so the held state never blinks.
          const from = frameLooks(sequence.frames.at(-1)!, palette);
          const laps = this.#start(compileFrames(lap, { palette, tempo, from }), { iterations: Infinity, fill: 'none' });
          for (const animation of entry) animation.cancel();
          this.#animations = laps;
        } else if (!sequence.hold) {
          this.#animations = [];
        }
        return true;
      });
    });
  }

  /**
   * The held state cleared: plays the exit of the holding sequence, from whatever the mark
   * shows now, back to the resting H. Without a held state it settles to the H.
   */
  async release(): Promise<boolean> {
    const held = this.#held;
    const generation = ++this.#generation;
    this.#held = null;
    if (!held?.hold || this.#reducedMotion.matches) return this.#settle(generation);
    const palette = this.#resolvedPalette();
    const from = this.#capture();
    this.#cancel();
    const exit = this.#start(compileFrames(held.hold.exit, { palette, tempo: held.tempo, from }), { iterations: 1, fill: 'none' });
    return this.#finished(exit, generation).then((finished) => {
      if (finished) this.#animations = [];
      return finished;
    });
  }

  /** Stops whatever plays or holds and settles to the resting H. */
  stop(): Promise<boolean> {
    this.#held = null;
    return this.#settle(++this.#generation);
  }

  /** The holding sequence whose state the mark shows (or is entering), if any. */
  get held(): Sequence | null {
    return this.#held;
  }

  /** The hover interaction: pills step apart with a spring, and spring back when false. */
  stepApart(on: boolean): void {
    const apart = on && !this.#reducedMotion.matches;
    for (const segment of SEGMENTS) {
      const rect = this.#rects[segment];
      const [x, y] = STEP_APART.offsets[segment];
      rect.style.transition = `transform ${STEP_APART.ms}ms ${STEP_APART.easing}`;
      rect.style.transform = apart ? `translate(${x}px, ${y}px)` : '';
    }
  }

  /** Playback speed for slow-motion review; 0.25 plays four times slower. */
  setRate(rate: number): void {
    this.#rate = rate;
    for (const animation of this.#animations) animation.updatePlaybackRate(rate);
  }

  destroy(): void {
    this.#generation++;
    this.#cancel();
    this.#observer.disconnect();
    document.removeEventListener('visibilitychange', this.#onVisibility);
    this.#reducedMotion.removeEventListener('change', this.#onReducedMotion);
  }

  #resolvedPalette(): Partial<Record<Colour, string>> {
    if (this.#palette.bone) return this.#palette;
    // bone is the mark's own colour; a detached SVG has no computed colour to give.
    const bone = this.svg.isConnected ? getComputedStyle(this.svg).color : '';
    if (!bone) throw new Error('MarkPlayer: the SVG must be in the document before it plays (bone resolves to its currentColor)');
    return { ...this.#palette, bone };
  }

  #cancel() {
    for (const animation of this.#animations) animation.cancel();
    this.#animations = [];
  }

  /** What every segment shows right now, mid-animation included. */
  #capture(): Record<Segment, Look> {
    return Object.fromEntries(
      SEGMENTS.map((segment) => {
        const style = getComputedStyle(this.#rects[segment]);
        return [segment, { opacity: Number(style.opacity), fill: style.fill }];
      }),
    ) as Record<Segment, Look>;
  }

  #start(compiled: CompiledFrames, timing: { iterations: number; fill: FillMode }): Animation[] {
    const animations = SEGMENTS.map((segment) =>
      this.#rects[segment].animate(compiled.tracks[segment] as unknown as Keyframe[], { duration: compiled.duration, ...timing }),
    );
    this.#animations = animations;
    this.#syncPaused();
    return animations;
  }

  /** True when every animation finished and nothing newer took over. */
  #finished(animations: Animation[], generation: number): Promise<boolean> {
    return Promise.all(animations.map((animation) => animation.finished)).then(
      () => generation === this.#generation,
      () => false, // cancelled by a newer play, release or stop
    );
  }

  /** Shows a state without motion (reduced motion): a zero-change animation that holds it. */
  #showStill(looks: Record<Segment, Look>) {
    this.#animations = SEGMENTS.map((segment) =>
      this.#rects[segment].animate([{ ...looks[segment] }, { ...looks[segment] }], { duration: 1, fill: 'forwards' }),
    );
  }

  /** Moves from what the mark shows now to the resting H; resolves false if superseded. */
  #settle(generation: number): Promise<boolean> {
    const current = this.#capture();
    this.#cancel();
    // Without animations the rects show their markup state: the resting H.
    const rest = this.#capture();
    const moving = SEGMENTS.some(
      (segment) => Math.abs(current[segment].opacity - rest[segment].opacity) > 0.01 || current[segment].fill !== rest[segment].fill,
    );
    if (!moving || this.#reducedMotion.matches) return Promise.resolve(generation === this.#generation);

    this.#animations = SEGMENTS.map((segment) =>
      this.#rects[segment].animate([{ ...current[segment], easing: SETTLE_EASING }, { ...rest[segment] }], {
        duration: SETTLE_MS,
        fill: 'none',
      }),
    );
    this.#syncPaused();
    return this.#finished(this.#animations, generation);
  }

  #syncPaused() {
    const paused = !this.#onScreen || document.hidden;
    for (const animation of this.#animations) {
      animation.playbackRate = this.#rate;
      // Only running animations pause: play() on a finished one (a held state) would restart it.
      if (paused && animation.playState === 'running') animation.pause();
      else if (!paused && animation.playState === 'paused') animation.play();
    }
  }
}

/** Builds the mark as an inline SVG ready for MarkPlayer. Resting H, segments in currentColor. */
export function createMarkSvg(options: { size: number; tile?: boolean; crop?: boolean; title?: string }): SVGSVGElement {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  // crop: just the digit, as in the website nav (size is the height). Otherwise the 120 grid.
  svg.setAttribute('viewBox', options.crop ? '22 14 76 92' : '0 0 120 120');
  svg.setAttribute('width', String(options.crop ? Math.round((options.size * 76) / 92) : options.size));
  svg.setAttribute('height', String(options.size));
  svg.setAttribute('class', 'mark');
  if (options.title) {
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', options.title);
  } else {
    svg.setAttribute('aria-hidden', 'true');
  }
  if (options.tile) {
    const tile = document.createElementNS(ns, 'rect');
    tile.setAttribute('width', '120');
    tile.setAttribute('height', '120');
    tile.setAttribute('rx', '27');
    tile.setAttribute('class', 'mark-tile');
    svg.append(tile);
  }
  for (const segment of SEGMENTS) {
    const rect = document.createElementNS(ns, 'rect');
    const box = GEOMETRY[segment];
    rect.setAttribute('x', String(box.x));
    rect.setAttribute('y', String(box.y));
    rect.setAttribute('width', String(box.width));
    rect.setAttribute('height', String(box.height));
    rect.setAttribute('rx', String(PILL_RADIUS));
    rect.setAttribute('fill', 'currentColor');
    rect.setAttribute('opacity', String(restLook(segment).opacity));
    rect.dataset.seg = segment;
    svg.append(rect);
  }
  return svg;
}
