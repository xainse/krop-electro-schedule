const { loadFunctions } = require('./setup');

let hoursFromIntervals;

beforeAll(() => {
  const fns = loadFunctions();
  hoursFromIntervals = fns.hoursFromIntervals;
});

describe('hoursFromIntervals', () => {
  test('returns array of 24 elements', () => {
    const result = hoursFromIntervals([]);
    expect(result).toHaveLength(24);
  });

  test('empty intervals returns all true', () => {
    const result = hoursFromIntervals([]);
    expect(result.every(v => v === true)).toBe(true);
  });

  test('single off interval "02:00-04:00"', () => {
    const result = hoursFromIntervals([
      { start: '02:00', end: '04:00', off: true }
    ]);
    expect(result[0]).toBe(true);
    expect(result[1]).toBe(true);
    expect(result[2]).toBe(false);
    expect(result[3]).toBe(false);
    expect(result[4]).toBe(true);
  });

  test('multiple off intervals', () => {
    const result = hoursFromIntervals([
      { start: '02:00', end: '04:00', off: true },
      { start: '10:00', end: '12:00', off: true },
    ]);
    expect(result[2]).toBe(false);
    expect(result[3]).toBe(false);
    expect(result[10]).toBe(false);
    expect(result[11]).toBe(false);
    expect(result[0]).toBe(true);
    expect(result[5]).toBe(true);
    expect(result[12]).toBe(true);
  });

  test('interval with status "off"', () => {
    const result = hoursFromIntervals([
      { start: '06:00', end: '08:00', status: 'off' }
    ]);
    expect(result[6]).toBe(false);
    expect(result[7]).toBe(false);
    expect(result[5]).toBe(true);
    expect(result[8]).toBe(true);
  });

  test('on interval explicitly sets hours to true', () => {
    const result = hoursFromIntervals([
      { start: '00:00', end: '24:00', off: true },
      { start: '10:00', end: '12:00', on: true },
    ]);
    expect(result[10]).toBe(true);
    expect(result[11]).toBe(true);
    expect(result[0]).toBe(false);
    expect(result[23]).toBe(false);
  });

  test('intervals without start/end are skipped', () => {
    const result = hoursFromIntervals([
      { off: true },
      { start: '06:00', off: true },
    ]);
    expect(result.every(v => v === true)).toBe(true);
  });

  test('midnight crossing interval', () => {
    const result = hoursFromIntervals([
      { start: '22:00', end: '02:00', off: true }
    ]);
    expect(result[22]).toBe(false);
    expect(result[23]).toBe(false);
    expect(result[0]).toBe(false);
    expect(result[1]).toBe(false);
    expect(result[2]).toBe(true);
  });

  test('uses "from/to" aliases for start/end', () => {
    const result = hoursFromIntervals([
      { from: '04:00', to: '06:00', off: true }
    ]);
    expect(result[4]).toBe(false);
    expect(result[5]).toBe(false);
    expect(result[3]).toBe(true);
    expect(result[6]).toBe(true);
  });
});
