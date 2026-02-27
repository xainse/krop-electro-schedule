const { loadFunctions } = require('./setup');

let calculateDailyStats;

beforeAll(() => {
  const fns = loadFunctions();
  calculateDailyStats = fns.calculateDailyStats;
});

describe('calculateDailyStats', () => {
  test('all on (48 true) returns 24h on, 0h off', () => {
    const periods = Array(48).fill(true);
    const stats = calculateDailyStats(periods);
    expect(stats.onHours).toBe(24);
    expect(stats.onMinutes).toBe(0);
    expect(stats.offHours).toBe(0);
    expect(stats.offMinutes).toBe(0);
  });

  test('all off (48 false) returns 0h on, 24h off', () => {
    const periods = Array(48).fill(false);
    const stats = calculateDailyStats(periods);
    expect(stats.onHours).toBe(0);
    expect(stats.onMinutes).toBe(0);
    expect(stats.offHours).toBe(24);
    expect(stats.offMinutes).toBe(0);
  });

  test('half and half (24 true, 24 false)', () => {
    const periods = [...Array(24).fill(true), ...Array(24).fill(false)];
    const stats = calculateDailyStats(periods);
    expect(stats.onHours).toBe(12);
    expect(stats.onMinutes).toBe(0);
    expect(stats.offHours).toBe(12);
    expect(stats.offMinutes).toBe(0);
  });

  test('odd number of half-hours produces 30 minutes remainder', () => {
    // 25 on, 23 off
    const periods = [...Array(25).fill(true), ...Array(23).fill(false)];
    const stats = calculateDailyStats(periods);
    expect(stats.onHours).toBe(12);
    expect(stats.onMinutes).toBe(30);
    expect(stats.offHours).toBe(11);
    expect(stats.offMinutes).toBe(30);
  });

  test('all null (unknown) returns 0h on, 0h off', () => {
    const periods = Array(48).fill(null);
    const stats = calculateDailyStats(periods);
    expect(stats.onHours).toBe(0);
    expect(stats.onMinutes).toBe(0);
    expect(stats.offHours).toBe(0);
    expect(stats.offMinutes).toBe(0);
  });

  test('mixed with nulls: only counts true and false', () => {
    // 10 true, 5 false, 33 null
    const periods = [
      ...Array(10).fill(true),
      ...Array(5).fill(false),
      ...Array(33).fill(null),
    ];
    const stats = calculateDailyStats(periods);
    expect(stats.onHours).toBe(5);
    expect(stats.onMinutes).toBe(0);
    expect(stats.offHours).toBe(2);
    expect(stats.offMinutes).toBe(30);
  });

  test('single half-hour on', () => {
    const periods = Array(48).fill(false);
    periods[0] = true;
    const stats = calculateDailyStats(periods);
    expect(stats.onHours).toBe(0);
    expect(stats.onMinutes).toBe(30);
    expect(stats.offHours).toBe(23);
    expect(stats.offMinutes).toBe(30);
  });
});
