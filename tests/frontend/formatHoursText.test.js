const { loadFunctions } = require('./setup');

let formatHoursText;

beforeAll(() => {
  const fns = loadFunctions();
  formatHoursText = fns.formatHoursText;
});

describe('formatHoursText', () => {
  test('formats 12 hours 0 minutes as "12:00"', () => {
    expect(formatHoursText(12, 0)).toBe('12:00');
  });

  test('formats 5 hours 30 minutes as "5:30"', () => {
    expect(formatHoursText(5, 30)).toBe('5:30');
  });

  test('formats 0 hours 0 minutes as "0:00"', () => {
    expect(formatHoursText(0, 0)).toBe('0:00');
  });

  test('formats 24 hours 0 minutes as "24:00"', () => {
    expect(formatHoursText(24, 0)).toBe('24:00');
  });

  test('formats 0 hours 30 minutes as "0:30"', () => {
    expect(formatHoursText(0, 30)).toBe('0:30');
  });

  test('pads single-digit minutes with zero', () => {
    expect(formatHoursText(3, 5)).toBe('3:05');
  });
});
