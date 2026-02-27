const { loadFunctions } = require('./setup');

let initialGrid;

beforeAll(() => {
  const fns = loadFunctions();
  initialGrid = fns.initialGrid;
});

describe('initialGrid', () => {
  test('creates 24 grid cells', () => {
    initialGrid();
    const grid = document.getElementById('grid');
    const cells = grid.querySelectorAll('.cell');
    expect(cells.length).toBe(24);
  });

  test('each cell has data-h attribute from 0 to 23', () => {
    initialGrid();
    const grid = document.getElementById('grid');
    const cells = grid.querySelectorAll('.cell');
    cells.forEach((cell, i) => {
      expect(cell.dataset.h).toBe(String(i));
    });
  });

  test('each cell displays formatted hour (00:00, 01:00, ... 23:00)', () => {
    initialGrid();
    const grid = document.getElementById('grid');
    const cells = grid.querySelectorAll('.cell');
    cells.forEach((cell, i) => {
      const hourText = cell.querySelector('.hour').textContent;
      expect(hourText).toBe(`${String(i).padStart(2, '0')}:00`);
    });
  });

  test('each cell starts with "?" emoji (unknown state)', () => {
    initialGrid();
    const grid = document.getElementById('grid');
    const cells = grid.querySelectorAll('.cell');
    cells.forEach(cell => {
      const emoji = cell.querySelector('.emoji');
      expect(emoji.textContent).toBe('?');
    });
  });

  test('each cell has sr-only "Невідомо" label', () => {
    initialGrid();
    const grid = document.getElementById('grid');
    const cells = grid.querySelectorAll('.cell');
    cells.forEach(cell => {
      const srOnly = cell.querySelector('.sr-only');
      expect(srOnly.textContent).toBe('Невідомо');
    });
  });

  test('calling initialGrid twice replaces old cells', () => {
    initialGrid();
    initialGrid();
    const grid = document.getElementById('grid');
    const cells = grid.querySelectorAll('.cell');
    expect(cells.length).toBe(24);
  });
});
