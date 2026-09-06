# Initialization acceptance

This repository is an isolated public test copy, authorized on 2026-09-06.
It contains no original Git history or operational data. Original source project
was read only. The initial publication contains 24 explicitly selected files.

## Evidence

- Native mise preparation installed OMX, local Beads and structural RepoWise.
- Initial OMX diagnostic: 19 passed, 0 warnings, 1 failed (missing AGENTS.md).
- Root cause: persisted merge-agents policy suppresses missing plugin defaults.
- Native recovery: project plugin setup with --clear-merge-agents-policy --force.
- Repeated OMX diagnostic: 20 passed, 0 warnings, 0 failed.
- All six offline PHP test files pass; syntax checks include short PHP tags.
- Gitleaks directory and initial Git history scans: no findings.
- Initial GitHub CI: https://github.com/arwoxbx24/kint-b24-init-test/actions/runs/34032448453
- Active ruleset: https://github.com/arwoxbx24/kint-b24-init-test/rules/22385133
- A real direct push was rejected with GH013: pull request required and
  `PHP offline checks` expected. The protected branch was not changed.
- PR #1 checks succeeded; GitHub reported BLOCKED / REVIEW_REQUIRED.
- GitHub secret scanning and push protection are enabled; alerts list was empty.
- Repeat mise preparation skipped Git, OMX and index initialization; the OMX
  diagnostic again reported 20 passed, 0 warnings, 0 failed.
- A fresh `omx exec` using the existing authenticated user Codex home returned
  `OMX_KINT_TEST_READY`, exit 0, with SessionStart/UserPromptSubmit/Stop hooks.
  The isolated project home has no credentials and returned HTTP 401 when used
  alone. No credentials were copied; use the existing authenticated home to run.
- Original project HEAD remained 5164ca3c718b7e38df5679d59a5addaa1ff596b9
  with a clean worktree after the exercise.

## Acceptance contract

The default branch requires a pull request, one independent approving reviewer,
resolved review discussions and the GitHub Actions check `PHP offline checks`.
Direct pushes, force pushes and deletion are restricted. No bypass actor is set.

Opening a passing PR does not imply it was approved or merged. The author cannot
supply their own independent GitHub approval. No production deployment is tested.
Existing prototype runtime defects are outside this initialization exercise.
