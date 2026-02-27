const { loadFunctions } = require('./setup');

let render, initialGrid;

beforeAll(() => {
  const fns = loadFunctions();
  render = fns.render;
  initialGrid = fns.initialGrid;
});

beforeEach(() => {
  initialGrid();
});

function getCell(hour) {
  return document.querySelector(`.cell[data-h="${hour}"]`);
}

describe('render', () => {
  test('all-on schedule shows power emoji on all cells', () => {
    const periods = Array(48).fill(true);
    render(periods);
    for (let h = 0; h < 24; h++) {
      const cell = getCell(h);
      expect(cell.querySelector('.emoji').textContent).toBe('⚡');
      expect(cell.querySelector('.sr-only').textContent).toBe('Є електрика');
    }
  });

  test('all-off schedule shows moon emoji on all cells', () => {
    const periods = Array(48).fill(false);
    render(periods);
    for (let h = 0; h < 24; h++) {
      const cell = getCell(h);
      expect(cell.querySelector('.emoji').textContent).toBe('🌑');
      expect(cell.querySelector('.sr-only').textContent).toBe('Відключено');
      expect(cell.classList.contains('has-white-text')).toBe(true);
    }
  });

  test('mixed cell: first half off, second half on', () => {
    const periods = Array(48).fill(true);
    periods[10] = false; // Hour 5, first half off
    // periods[11] stays true -> second half on
    render(periods);
    const cell = getCell(5);
    expect(cell.querySelector('.emoji').textContent).toBe('🌑⚡');
    expect(cell.querySelector('.sr-only').textContent).toBe('Відключено першу півгодину');
    expect(cell.classList.contains('half-left-off')).toBe(true);
  });

  test('mixed cell: first half on, second half off', () => {
    const periods = Array(48).fill(true);
    // periods[10] stays true -> first half on
    periods[11] = false; // Hour 5, second half off
    render(periods);
    const cell = getCell(5);
    expect(cell.querySelector('.emoji').textContent).toBe('⚡🌑');
    expect(cell.querySelector('.sr-only').textContent).toBe('Відключено другу півгодину');
    expect(cell.classList.contains('half-right-off')).toBe(true);
  });

  test('unknown state (null) shows ellipsis', () => {
    const periods = Array(48).fill(null);
    render(periods);
    for (let h = 0; h < 24; h++) {
      const cell = getCell(h);
      expect(cell.querySelector('.emoji').textContent).toBe('…');
      expect(cell.querySelector('.sr-only').textContent).toBe('Невідомо');
    }
  });

  test('updates statistics display', () => {
    const periods = Array(48).fill(true);
    // Make hours 0-3 off (8 half-hours = 4 hours)
    for (let i = 0; i < 8; i++) periods[i] = false;
    render(periods);
    const onStat = document.getElementById('hoursOnStat');
    const offStat = document.getElementById('hoursOffStat');
    expect(onStat.textContent).toBe('20:00');
    expect(offStat.textContent).toBe('4:00');
  });

  test('render clears previous half-off classes', () => {
    const periods1 = Array(48).fill(true);
    periods1[10] = false; // half-left-off on hour 5
    render(periods1);
    const cell = getCell(5);
    expect(cell.classList.contains('half-left-off')).toBe(true);

    const periods2 = Array(48).fill(true);
    render(periods2);
    expect(cell.classList.contains('half-left-off')).toBe(false);
    expect(cell.classList.contains('half-right-off')).toBe(false);
    expect(cell.classList.contains('has-white-text')).toBe(false);
  });

  test('real-world schedule renders correctly', () => {
    const periods = Array(48).fill(true);
    // 02:00-04:00 off (indices 4-7)
    for (let i = 4; i < 8; i++) periods[i] = false;
    // 10:00-11:30 off (indices 20-22)
    for (let i = 20; i < 23; i++) periods[i] = false;

    render(periods);

    expect(getCell(0).querySelector('.emoji').textContent).toBe('⚡');
    expect(getCell(2).querySelector('.emoji').textContent).toBe('🌑');
    expect(getCell(3).querySelector('.emoji').textContent).toBe('🌑');
    expect(getCell(4).querySelector('.emoji').textContent).toBe('⚡');
    expect(getCell(10).querySelector('.emoji').textContent).toBe('🌑');
    // Hour 11: index 22 = false (11:00-11:30), index 23 = true (11:30-12:00) -> mixed
    expect(getCell(11).querySelector('.emoji').textContent).toBe('🌑⚡');
  });
});
