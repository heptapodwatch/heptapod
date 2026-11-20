// Copied from heptapod.studio/mark/src/format.ts by scripts/sync-mark-errors.mjs. Do not edit here.
// The sequence format for the Heptapod mark: an H lit on one seven-segment digit.
//
// A sequence is plain data. Each frame says which state every segment should reach and
// how it gets there. A segment can only change its opacity and its fill colour; it never
// moves and never changes size. The one movement the mark knows, the hover "step apart",
// is not a sequence: it lives in the player as a separate interaction.

export const SEGMENTS = ['a', 'b', 'c', 'd', 'e', 'f', 'g'] as const;
/** Standard seven-segment naming: a top, b top-right, c bottom-right, d bottom, e bottom-left, f top-left, g middle. */
export type Segment = (typeof SEGMENTS)[number];

/** The resting H lights these; a and d stay as ghosts. */
export const REST_LIT: readonly Segment[] = ['b', 'c', 'e', 'f', 'g'];

/** Named opacities. A number between 0 and 1 is also a valid level. */
export const LEVELS = { lit: 1, ghost: 0.16, off: 0 } as const;
export type Level = keyof typeof LEVELS | number;

/**
 * Colour tokens, by meaning. `bone` is the mark's own colour: the player resolves it to the
 * SVG's `currentColor`, so the mark follows light and dark themes. The others are semantic
 * and carry the current Heptapod values; the app maps each token to its own.
 */
export const PALETTE = {
  bone: '#e6e9ec',
  accent: '#9fd6ff', // brand Ice blue: running, attention. The one place to change it.
  ok: '#79ad95', // up, passed, recovered
  warn: '#dd8a43', // warning, late, reconnecting
  down: '#e6574d', // down, failed
  info: '#7f9fc0', // maintenance, syncing
  error: '#8b96a1', // the check itself failed
  pending: '#68737e', // waiting for a first result
  paused: '#68737e', // monitoring paused
  disabled: '#636d78', // switched off
} as const;
export type Colour = keyof typeof PALETTE;

/**
 * Easing tokens. Curves come from Emil Kowalski's animation guidance and easings.net;
 * `step` switches at the start of the transition, like a real display.
 */
export const EASINGS = {
  step: 'steps(1, start)',
  linear: 'linear',
  out: 'cubic-bezier(0.23, 1, 0.32, 1)', // strong ease-out: arrivals, afterglow
  'in-out': 'cubic-bezier(0.77, 0, 0.175, 1)', // strong ease-in-out
  gentle: 'ease', // colour changes
  sine: 'cubic-bezier(0.37, 0, 0.63, 1)', // easeInOutSine: breathing, pulses
} as const;
export type Ease = keyof typeof EASINGS;

export interface SegmentState {
  /** Target opacity. Default: the segment's resting level (lit for the H, ghost for a and d). */
  level?: Level;
  /** Target colour. Default: bone. */
  colour?: Colour;
  /** Overrides the frame's easing for this segment. */
  ease?: Ease;
  /** Milliseconds after the frame start before this segment's transition begins. Default 0. */
  delay?: number;
  /** Length of this segment's transition in ms. Default: the frame's transition. delay + transition must fit in the frame. */
  transition?: number;
}

export interface Frame {
  /** Length of the frame in ms. The next frame starts after it. */
  ms: number;
  /** Easing for every segment that changes in this frame. */
  ease: Ease;
  /** Length of the transitions in ms. Default: ms (the change takes the whole frame). */
  transition?: number;
  /** Target state per segment. A segment that is not listed returns to its resting state. */
  segments?: Partial<Record<Segment, SegmentState>>;
}

export const CATEGORIES = {
  boot: 'Boot and reveal',
  running: 'Running and progress',
  idle: 'Live and idle',
  status: 'Status',
  display: 'Display',
  glyphs: 'Text and glyphs',
  patterns: 'Patterns',
  playful: 'Playful',
} as const;
export type Category = keyof typeof CATEGORIES;

/** Categories whose sequences may hold a state: they stand for something that lasts. */
export const HOLD_CATEGORIES: readonly Category[] = ['display', 'running'];

/** product-ready: could ship as it is. exploration: shows what the display can do; draw from it. */
export const READINESS = ['product-ready', 'exploration'] as const;
export type Readiness = (typeof READINESS)[number];

export interface Sequence {
  /** Stable kebab-case id, prefixed with the category. */
  id: string;
  name: string;
  category: Category;
  /** Where it is meant to play. */
  intent: string;
  readiness: Readiness;
  /** Playback speed factor; 1 plays the frames as written. */
  tempo: number;
  /** True when the whole sequence may repeat while a state lasts (running, live). Never with hold. */
  loop: boolean;
  /**
   * Plays from the resting H. Without `hold`, the last frame returns to the H.
   * With `hold`, the last frame is the held state (a glyph, a number) and it stays.
   */
  frames: Frame[];
  /** Display and running sequences only (HOLD_CATEGORIES): a state that stays until the app clears it. */
  hold?: Hold;
}

/**
 * A held end state. The held state is where `frames` ends. While the state lasts, the
 * optional `loop` repeats, each lap starting and ending on the held state. When the state
 * clears, `exit` plays and returns to the resting H.
 */
export interface Hold {
  loop?: Frame[];
  exit: Frame[];
}

/** One segment's resolved look at the end of a frame. */
export interface SegmentLook {
  opacity: number;
  colour: Colour;
}

export function levelOpacity(level: Level): number {
  return typeof level === 'number' ? level : LEVELS[level];
}

export function restLook(segment: Segment): SegmentLook {
  return { opacity: REST_LIT.includes(segment) ? LEVELS.lit : LEVELS.ghost, colour: 'bone' };
}

export function frameLook(frame: Frame, segment: Segment): SegmentLook {
  const state = frame.segments?.[segment];
  const rest = restLook(segment);
  return {
    opacity: state?.level === undefined ? rest.opacity : levelOpacity(state.level),
    colour: state?.colour ?? rest.colour,
  };
}

// Geometry of the heavy variant in a 120×120 grid: pills 13 wide, 2.5 gap,
// stroke centres x 40/80 and y 26/60/94.
const T = 13;
const GAP = 2.5;
const [XL, XR, YT, YM, YB] = [40, 80, 26, 60, 94];
const vertical = (x: number, y1: number, y2: number) => ({ x: x - T / 2, y: y1, width: T, height: y2 - y1 });
const horizontal = (y: number) => ({ x: XL + T / 2 + GAP, y: y - T / 2, width: XR - XL - T - 2 * GAP, height: T });

export const GEOMETRY: Record<Segment, { x: number; y: number; width: number; height: number }> = {
  a: horizontal(YT),
  b: vertical(XR, YT + GAP, YM - GAP),
  c: vertical(XR, YM + GAP, YB - GAP),
  d: horizontal(YB),
  e: vertical(XL, YM + GAP, YB - GAP),
  f: vertical(XL, YT + GAP, YM - GAP),
  g: horizontal(YM),
};
export const PILL_RADIUS = T / 2;

/**
 * The hover interaction, the only time segments move: each pill steps a few units
 * outward from the centre with a spring, and springs back on leave.
 * Same offsets and curve as the website's `.hp-hover`.
 */
export const STEP_APART = {
  offsets: {
    a: [0, -3],
    b: [3, -1.5],
    c: [3, 1.5],
    d: [0, 3],
    e: [-3, 1.5],
    f: [-3, -1.5],
    g: [0, 0],
  } as Record<Segment, readonly [number, number]>,
  ms: 380,
  easing: 'cubic-bezier(0.34, 1.56, 0.64, 1)',
};
