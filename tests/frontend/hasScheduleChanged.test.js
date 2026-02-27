const { loadFunctions } = require('./setup');

let hasScheduleChanged;

beforeAll(() => {
  const fns = loadFunctions();
  hasScheduleChanged = fns.hasScheduleChanged;
});

describe('hasScheduleChanged', () => {
  test('returns false when prev is null (first load)', () => {
    const current = Array(48).fill(true);
    expect(hasScheduleChanged(null, current)).toBe(false);
  });

  test('returns false when schedules are identical', () => {
    const a = Array(48).fill(true);
    const b = Array(48).fill(true);
    expect(hasScheduleChanged(a, b)).toBe(false);
  });

  test('returns true when schedules differ', () => {
    const prev = Array(48).fill(true);
    const current = Array(48).fill(true);
    current[10] = false;
    expect(hasScheduleChanged(prev, current)).toBe(true);
  });

  test('returns false for same mixed schedule', () => {
    const schedule = [...Array(24).fill(true), ...Array(24).fill(false)];
    const copy = [...schedule];
    expect(hasScheduleChanged(schedule, copy)).toBe(false);
  });

  test('detects change at single position', () => {
    const prev = Array(48).fill(false);
    const current = Array(48).fill(false);
    current[47] = true;
    expect(hasScheduleChanged(prev, current)).toBe(true);
  });

  test('returns false for two null arrays', () => {
    expect(hasScheduleChanged(null, null)).toBe(false);
  });
});
