AI Code-Quality Rules for Automated Edits

Purpose
- Provide a concise rule for AI tools to produce cleaner, non-redundant code.

Rule (single statement)
- Prefer reusing existing functions and variables; avoid redundant code and unnecessary new functions when existing variables or functions can be recycled to express the flow.

Guidelines
- Reuse: Check for existing helper functions and variables before creating new ones.
- Simplicity: Keep functions focused and small; prefer parameters over new global state.
- No redundant duplication: If logic is repeated, extract or reuse rather than copy-paste.
- Avoid extra flow-wrapping functions: Do not create a new wrapper function solely to pass data if the same variables or functions can be reused in the current scope.
- Naming: Use clear, consistent names for variables and functions; rename only when it improves clarity.
- Comments: Prefer self-documenting code; add brief comments only where intent is unclear.
- Safety: Preserve existing behavior and tests; prefer minimally invasive refactors.

Examples
- Bad (redundant new function):
  function processOrderFlow(order) {
    // duplicates logic that already exists in processOrder
    return processOrder(order);
  }

- Good (reuse existing function):
  // call existing processOrder directly
  processOrder(order);

When to extract a new function
- Extract when a block of code is reused in multiple places or when a single function exceeds a clear responsibility boundary.
- Do not extract a function only to avoid passing variables; prefer passing parameters or reusing scope when appropriate.

Suggested next steps
- Add a short reference to this file in the project README so contributors and tools can find it.
- Optionally integrate this rule in code-review checklists or linter/CI guidance.

Fixing coding structure for AI use
- Purpose: Make AI-driven edits safer and more consistent by specifying preferred structural fixes.
- Prefer small, reversible refactors: when adjusting structure, keep changes minimal and clearly scoped.
- Preserve variable reuse: prefer reusing existing variables and function signatures; only add new symbols when necessary.
- Maintain call-flow: avoid creating wrapper functions solely to move data between scopes — pass parameters or reuse scope instead.
- Formatting and linting: apply project linters/formatters after refactors (e.g., PHP CS Fixer, phpcs) and keep diffs readable.
- Tests and manual checks: when changing structure in shared code, run or add small tests where practicable and include brief manual verification notes in the commit message.
- Examples:
  - Good: rename an internal variable for clarity without changing function boundaries.
  - Good: extract a helper only when the same block is used in multiple files.
  - Bad: create a one-line wrapper function just to avoid passing an existing variable into a call.

Suggested workflow for AI edits
- Detect existing helpers and reuse them before introducing new helpers.
- If structural change is required, make it in one commit with a short note explaining the purpose and the minimal scope.
- Run automated formatters and linters, and include the lint command in CI so future AI edits can be auto-validated.
