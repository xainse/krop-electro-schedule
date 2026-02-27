/**
 * Extracts and evaluates pure JavaScript functions from index.html
 * for unit testing. Mocks DOM elements and browser APIs as needed.
 */
const fs = require('fs');
const path = require('path');

function createDomEnvironment() {
  document.body.innerHTML = `
    <div class="wrap">
      <div class="title">Графік відключень — черга 1.1</div>
      <select id="queueSelect"><option>1.1</option></select>
      <button id="refreshBtn">Оновити</button>
      <span id="updatedMeta"></span>
      <div id="apiErrorMsg" style="display:none;"></div>
      <div id="emergencyMsg" style="display:none;"></div>
      <div class="legend">
        <span id="hoursOnStat"></span>
        <span id="hoursOffStat"></span>
      </div>
      <div id="statusMsg"></div>
      <div id="errorMsg" style="display:none;"></div>
      <div id="grid" class="grid"></div>
      <span id="version"></span>
      <span id="notificationStatusText"></span>
      <a id="requestNotificationLink" style="display:none;"></a>
      <table><tbody id="overviewTableBody"></tbody></table>
    </div>
  `;
}

/**
 * Extract a named function definition (including its full body) from source code.
 * Handles nested braces correctly.
 */
function extractFunctionBody(code, funcName) {
  // Match "function funcName(" or "async function funcName("
  const regex = new RegExp('((?:async\\s+)?function\\s+' + funcName + '\\s*\\([^)]*\\)\\s*\\{)');
  const match = code.match(regex);
  if (!match) return null;

  const startIdx = code.indexOf(match[0]);
  // Find opening brace
  const braceStart = code.indexOf('{', startIdx + match[0].length - 1);
  let depth = 1;
  let i = braceStart + 1;
  while (i < code.length && depth > 0) {
    if (code[i] === '{') depth++;
    else if (code[i] === '}') depth--;
    i++;
  }

  return code.substring(startIdx, i);
}

function loadFunctions() {
  createDomEnvironment();

  const htmlPath = path.resolve(__dirname, '..', '..', 'index.html');
  const html = fs.readFileSync(htmlPath, 'utf-8');

  // Find all <script>...</script> blocks, take the last (main app script)
  const allMatches = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)];
  if (allMatches.length === 0) {
    throw new Error('Could not extract <script> block from index.html');
  }
  const scriptCode = allMatches[allMatches.length - 1][1];

  // Extract VERSION constant
  const versionMatch = scriptCode.match(/const\s+VERSION\s*=\s*'([^']+)'/);
  const VERSION = versionMatch ? versionMatch[1] : null;

  // Extract API_FETCH_TIMEOUT_MS
  const timeoutMatch = scriptCode.match(/const\s+API_FETCH_TIMEOUT_MS\s*=\s*(\d+)/);
  const API_FETCH_TIMEOUT_MS = timeoutMatch ? parseInt(timeoutMatch[1], 10) : 15000;

  // List of function names to extract
  const funcNames = [
    'fetchWithTimeout',
    'getUkraineHour',
    'initialGrid',
    'hoursFromIntervals',
    'parseHalfHourSchedule',
    'normalizeTo24',
    'render',
    'updateActiveHour',
    'calculateDailyStats',
    'formatHoursText',
    'updateActiveHourInTable',
    'requestNotificationPermission',
    'updateNotificationStatus',
    'hasScheduleChanged',
    'showNotification',
    'loadAllQueues',
    'renderOverviewTable',
    'load',
    'applyQueue',
  ];

  // Build a self-contained script from the extracted functions
  const extractedFunctions = funcNames
    .map(name => extractFunctionBody(scriptCode, name))
    .filter(Boolean)
    .join('\n\n');

  const wrappedCode = `
    (function() {
      // Mock browser APIs
      function apiUrl(p) { return p; }

      // DOM refs
      var gridEl = document.getElementById('grid');
      var statusMsg = document.getElementById('statusMsg');
      var overviewTableBody = document.getElementById('overviewTableBody');
      var titleEl = document.querySelector('.title');
      var apiErrorMsg = document.getElementById('apiErrorMsg');
      var emergencyMsg = document.getElementById('emergencyMsg');
      var updatedMeta = document.getElementById('updatedMeta');
      var errorMsg = document.getElementById('errorMsg');
      var refreshBtn = document.getElementById('refreshBtn');
      var versionEl = document.getElementById('version');
      var notificationStatusText = document.getElementById('notificationStatusText');
      var requestNotificationLink = document.getElementById('requestNotificationLink');
      var queueSelect = document.getElementById('queueSelect');

      // State variables
      var VERSION = '${VERSION}';
      var API_FETCH_TIMEOUT_MS = ${API_FETCH_TIMEOUT_MS};
      var currentQueue = '1.1';
      var previousHours = null;
      var lastSuccessfulData = null;
      var lastSuccessfulTime = null;
      var API_URL = 'api/blackout.php?queue=1.1';

      // Extracted functions
      ${extractedFunctions}

      return {
        VERSION: VERSION,
        parseHalfHourSchedule: parseHalfHourSchedule,
        normalizeTo24: normalizeTo24,
        hoursFromIntervals: hoursFromIntervals,
        calculateDailyStats: calculateDailyStats,
        formatHoursText: formatHoursText,
        hasScheduleChanged: hasScheduleChanged,
        render: render,
        initialGrid: initialGrid,
        updateActiveHour: updateActiveHour,
        getUkraineHour: getUkraineHour,
        showNotification: showNotification,
        renderOverviewTable: renderOverviewTable,
        updateActiveHourInTable: updateActiveHourInTable,
      };
    })();
  `;

  // eslint-disable-next-line no-eval
  const fns = eval(wrappedCode);
  return fns;
}

module.exports = { loadFunctions, createDomEnvironment };
