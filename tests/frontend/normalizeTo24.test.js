const { loadFunctions } = require('./setup');

let normalizeTo24;

beforeAll(() => {
  const fns = loadFunctions();
  normalizeTo24 = fns.normalizeTo24;
});

describe('normalizeTo24', () => {
  test('returns array of 48 elements for any valid input', () => {
    const result = normalizeTo24({ schedule: '02:00-04:00' });
    expect(result).toHaveLength(48);
  });

  test('schedule string with minutes (primary format)', () => {
    const result = normalizeTo24({ schedule: '02:00-04:00, 10:00-11:30' });
    expect(result).toHaveLength(48);
    // 02:00-04:00 => indices 4-7 are false
    expect(result[4]).toBe(false);
    expect(result[7]).toBe(false);
    // 10:00-11:30 => indices 20-22 are false
    expect(result[20]).toBe(false);
    expect(result[22]).toBe(false);
    expect(result[23]).toBe(true);
    // Non-outage periods are true
    expect(result[0]).toBe(true);
    expect(result[30]).toBe(true);
  });

  test('schedule string without minutes (old format "2-4, 6-8")', () => {
    const result = normalizeTo24({ schedule: '2-4, 6-8' });
    expect(result).toHaveLength(48);
    // Hour 2 => indices 4,5 should be false
    expect(result[4]).toBe(false);
    expect(result[5]).toBe(false);
    // Hour 3 => indices 6,7 should be false
    expect(result[6]).toBe(false);
    expect(result[7]).toBe(false);
    // Hour 4 => should be true (end is exclusive)
    expect(result[8]).toBe(true);
  });

  test('24-hour array format (hours array)', () => {
    const hours = Array(24).fill(true);
    hours[2] = false;
    hours[3] = false;
    // Note: in the code, number values map: v > 0 => false, v <= 0 => true
    // But boolean values map directly
    const result = normalizeTo24({ hours });
    expect(result).toHaveLength(48);
    // Hour 2 => indices 4,5
    expect(result[4]).toBe(false);
    expect(result[5]).toBe(false);
    // Hour 0 => true
    expect(result[0]).toBe(true);
  });

  test('off_hours list format', () => {
    const result = normalizeTo24({ off_hours: [2, 3, 6, 7] });
    expect(result).toHaveLength(48);
    // Hour 2 off => indices 4,5
    expect(result[4]).toBe(false);
    expect(result[5]).toBe(false);
    // Hour 6 off => indices 12,13
    expect(result[12]).toBe(false);
    expect(result[13]).toBe(false);
    // Hour 0 on
    expect(result[0]).toBe(true);
    expect(result[1]).toBe(true);
  });

  test('intervals format', () => {
    const result = normalizeTo24({
      intervals: [
        { start: '02:00', end: '04:00', off: true },
        { start: '10:00', end: '12:00', off: true },
      ]
    });
    expect(result).toHaveLength(48);
    // Hour 2 off, hour 3 off
    expect(result[4]).toBe(false);
    expect(result[5]).toBe(false);
    expect(result[6]).toBe(false);
    expect(result[7]).toBe(false);
    // Hour 10 off, hour 11 off
    expect(result[20]).toBe(false);
    expect(result[23]).toBe(false);
    // Others on
    expect(result[0]).toBe(true);
    expect(result[30]).toBe(true);
  });

  test('empty schedule (no blackouts) returns 48 true', () => {
    const result = normalizeTo24({ success: true, queue: '1.1', schedule: '' });
    expect(result).toHaveLength(48);
    expect(result.every(v => v === true)).toBe(true);
  });

  test('schedule "-" (no blackouts) returns 48 true', () => {
    const result = normalizeTo24({ success: true, queue: '1.1', schedule: '-' });
    expect(result).toHaveLength(48);
    expect(result.every(v => v === true)).toBe(true);
  });

  test('schedule null (no data for queue) returns 48 nulls', () => {
    const result = normalizeTo24({ success: true, queue: '1.1', schedule: null });
    expect(result).toHaveLength(48);
    expect(result.every(v => v === null)).toBe(true);
  });

  test('unknown format returns 48 nulls', () => {
    const result = normalizeTo24({ foo: 'bar' });
    expect(result).toHaveLength(48);
    expect(result.every(v => v === null)).toBe(true);
  });

  test('string input is parsed as interval string', () => {
    const result = normalizeTo24('02:00-04:00; 10:00-12:00');
    expect(result).toHaveLength(48);
    // Hour 2,3 off
    expect(result[4]).toBe(false);
    expect(result[7]).toBe(false);
  });

  test('schedule with success/queue/emergency_mode fields (real API response)', () => {
    const result = normalizeTo24({
      success: true,
      queue: '1.1',
      schedule: '02:00-04:00, 06:00-08:00, 10:00-11:30',
      emergency_mode: false,
      updated: 1706016000,
      source: 'https://kiroe.com.ua/electricity-blackout'
    });
    expect(result).toHaveLength(48);
    expect(result[4]).toBe(false);  // 02:00
    expect(result[12]).toBe(false); // 06:00
    expect(result[20]).toBe(false); // 10:00
    expect(result[22]).toBe(false); // 11:00-11:30
    expect(result[23]).toBe(true);  // 11:30
  });
});
