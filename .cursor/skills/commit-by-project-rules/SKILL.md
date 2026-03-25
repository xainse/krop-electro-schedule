---
name: commit-by-project-rules
description: Executes commits in this repository using project-specific Cursor rules. Use when user asks to commit, prepare commit, update version/changelog before commit, or push changes.
---

# Commit By Project Rules

## When to use

Use this skill when the user asks to:
- create a commit
- prepare changes for commit
- push changes
- "commit by project rules" or similar

## Source of truth

- Commit workflow rules: `.cursorrules`
- Version source: `index.html` -> `const VERSION = 'X.Y'`
- Changelog file: `docs/CHANGELOG.md`

## Mandatory workflow (do not skip)

1. Confirm the user explicitly asked to commit.
2. Inspect changes: `git status`, `git diff`, `git log --oneline`.
3. Update version in `index.html` (`const VERSION`) before commit.
4. Add a new top entry in `docs/CHANGELOG.md` using date `YYYY-MM-DD` and sections:
   - `### Додано`
   - `### Виправлено`
   - `### Змінено`
5. Run tests before commit:
   - `cd tests/frontend && npm test`
   - `cd tests/backend && vendor/bin/phpunit`
6. If frontend behavior/UI/e2e changed, also run:
   - `cd tests/frontend && npm run test:e2e`
7. If any test fails:
   - stop commit flow
   - report failed test name/file and expected vs actual
   - explain probable cause
   - propose concrete fix
   - re-run tests after fix
8. Stage all required changed/new files (`git add ...`).
9. Create commit.
10. Verify final state with `git status`.
11. Push only if user asked to push.

## Commit message style for this repo

Observed project history contains mostly concise Ukrainian messages and version markers.

Preferred format:
- Ukrainian concise summary, optionally with scope or prefix (`fix:`, `feat:`, `docs:`).
- Include version marker when release-like commit updates app version, for example: `(v3.9)`.

Examples:
- `fix: стабілізовано e2e fallback-тест (v3.9)`
- `Оновлено парсинг Telegram і кешування (v3.10)`
- `docs: оновлено CHANGELOG для v3.11`

## Version bump guidance

- Parse current `const VERSION = 'X.Y'`.
- Bump usually by `+0.1` for normal changes.
- Keep numeric dotted format (`'3.8' -> '3.9'`, `'3.9' -> '4.0'`).
- Ensure version in commit message and changelog header match.

## Changelog template

Add new section near top of `docs/CHANGELOG.md`:

```markdown
## [X.Y] - YYYY-MM-DD

### Додано
- ...

### Виправлено
- ...

### Змінено
- ...
```

## Safety constraints

- Never run destructive git commands (`reset --hard`, forced history rewrite).
- Never commit secrets or credential files.
- Never amend old commits unless user explicitly requests amend.
- Never push without explicit user request.

