const { loadFunctions } = require('./setup');

let parseHalfHourSchedule;

beforeAll(() => {
  const fns = loadFunctions();
  parseHalfHourSchedule = fns.parseHalfHourSchedule;
});

describe('parseHalfHourSchedule', () => {
  test('returns array of 48 elements', () => {
    const result = parseHalfHourSchedule('02:00-04:00');
    expect(result).toHaveLength(48);
  });

  test('empty string returns all true (power on)', () => {
    const result = parseHalfHourSchedule('');
    expect(result).toHaveLength(48);
    expect(result.every(v => v === true)).toBe(true);
  });

  test('standard range "02:00-04:00" marks correct indices as false', () => {
    const result = parseHalfHourSchedule('02:00-04:00');
    // Indices 4,5,6,7 (02:00-02:30, 02:30-03:00, 03:00-03:30, 03:30-04:00)
    for (let i = 0; i < 48; i++) {
      if (i >= 4 && i < 8) {
        expect(result[i]).toBe(false);
      } else {
        expect(result[i]).toBe(true);
      }
    }
  });

  test('half-hour boundary "10:00-11:30" marks indices 20,21,22 as false', () => {
    const result = parseHalfHourSchedule('10:00-11:30');
    expect(result[20]).toBe(false); // 10:00-10:30
    expect(result[21]).toBe(false); // 10:30-11:00
    expect(result[22]).toBe(false); // 11:00-11:30
    expect(result[23]).toBe(true);  // 11:30-12:00
    expect(result[19]).toBe(true);  // 09:30-10:00
  });

  test('full day "00:00-24:00" marks all 48 as false', () => {
    const result = parseHalfHourSchedule('00:00-24:00');
    expect(result.every(v => v === false)).toBe(true);
  });

  test('multiple ranges "02:00-04:00, 06:00-08:00"', () => {
    const result = parseHalfHourSchedule('02:00-04:00, 06:00-08:00');
    // 02:00-04:00 => indices 4-7
    for (let i = 4; i < 8; i++) expect(result[i]).toBe(false);
    // 06:00-08:00 => indices 12-15
    for (let i = 12; i < 16; i++) expect(result[i]).toBe(false);
    // Others should be true
    expect(result[0]).toBe(true);
    expect(result[8]).toBe(true);
    expect(result[16]).toBe(true);
  });

  test('edge case: last half hour "23:30-24:00"', () => {
    const result = parseHalfHourSchedule('23:30-24:00');
    expect(result[47]).toBe(false); // 23:30-24:00
    expect(result[46]).toBe(true);  // 23:00-23:30
  });

  test('old format without minutes "2-4, 6-8"', () => {
    const result = parseHalfHourSchedule('2-4, 6-8');
    // 2-4 => indices 4,5,6,7
    for (let i = 4; i < 8; i++) expect(result[i]).toBe(false);
    // 6-8 => indices 12,13,14,15
    for (let i = 12; i < 16; i++) expect(result[i]).toBe(false);
    expect(result[0]).toBe(true);
    expect(result[8]).toBe(true);
  });

  test('real-world schedule with many ranges', () => {
    const result = parseHalfHourSchedule('02:00-04:00, 06:00-08:00, 10:00-11:30, 14:00-16:00');
    const offRanges = [[4,8], [12,16], [20,23], [28,32]];
    for (let i = 0; i < 48; i++) {
      const shouldBeOff = offRanges.some(([start, end]) => i >= start && i < end);
      expect(result[i]).toBe(!shouldBeOff);
    }
  });

  test('handles en-dash separator "06:00–08:00"', () => {
    const result = parseHalfHourSchedule('06:00–08:00');
    for (let i = 12; i < 16; i++) expect(result[i]).toBe(false);
    expect(result[11]).toBe(true);
    expect(result[16]).toBe(true);
  });

  test('handles whitespace variations "02:00 - 04:00 , 06:00-08:00"', () => {
    const result = parseHalfHourSchedule('02:00 - 04:00 , 06:00-08:00');
    for (let i = 4; i < 8; i++) expect(result[i]).toBe(false);
    for (let i = 12; i < 16; i++) expect(result[i]).toBe(false);
  });
});
