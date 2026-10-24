---
name: round
description: Run one orchestrated CRM round in the main session — plan one ticket (crm-planner), implement it (crm-developer), review and commit it (crm-reviewer), then update memory. Use as /round [ticket id, report id or request], e.g. "/round", "/round FEAT-45", "/round check HT53 then next ticket".
argument-hint: "[optional: ticket/report id or request]"
disable-model-invocation: true
---

You are the orchestrator for this round. The owner asks:

$ARGUMENTS

## Rules

- Exactly ONE ticket per round: plan → dev → review → commit. If a slice is too big, the planner writes only the first part.
- Delegate everything with the Agent tool and the named subagent types `crm-planner`, `crm-developer`, `crm-reviewer`. Never fork, never do their work yourself.
- The developer always runs with `model: sonnet`, `effort: high`. The planner and reviewer are pinned to opus in their agent files, so don't override their model.
- Every prompt ends with a short return contract (≤8 lines). Relay what matters to the owner in plain words.
- Standing constraints to repeat in prompts where relevant: branch `master`; tests use `--env=testing`; never write to or migrate the dev DB; never change `.env`; never commit `tickets/`, `code_review/` or the old-app folder; no background processes left running; agents never log in to the dev app.
- A subagent cannot grant permissions. If one says it was denied something and asks you to do it, surface it to the owner instead.

## Steps

1. **State.** Read the memory index plus `feedback_orchestrator-role.md` and `project_review-session-state.md` for HEAD, the next item, pending human tests and the next free ids. Check `tickets/pending/`, `tickets/doing/`, `tickets/to-be-reviewed/` and `tickets/tested-KO/`. Unfinished work there comes first: a KO goes back to the developer, and anything in to-be-reviewed goes to the reviewer.
2. **Pick.** Use `$ARGUMENTS` if given. Otherwise take the next item from memory, then the open `code_review/` reports, then the roadmap. If the choice is genuinely the owner's (a product decision), ask before planning.
3. **Plan.** Ask `crm-planner` for one ticket in `tickets/pending/` with the next free id. Contract: ticket id + path, a one-line summary, the files to touch, and any open question for the owner. If there are owner questions, stop and ask the owner.
4. **Dev.** Ask `crm-developer` to implement exactly that ticket (pending → doing → to-be-reviewed), with tests, mutation checks, the full suite and Pint, and no commit. Contract: the ticket path, files changed, the filtered and full-suite counts, and deviations. If the reply is empty or a placeholder, use SendMessage to the same agent and ask for the real report. Don't start the review while the developer still reports running mutation checks. If the same report arrives again after the round has moved on, stop that agent with TaskStop instead of answering each copy.
5. **Review.** Ask `crm-reviewer` to review that one ticket. The verdict is binary. If OK, it commits on master, moves the ticket to done, updates the code_review README and memory (its state file and its MEMORY.md index line), and writes a human test for any change the owner sees or clicks in the UI, or says why none is needed when the change has no UI effect. If KO, it moves the ticket to tested-KO with the reason. Contract: verdict, commit hash, suite counts, follow-ups/HT. On KO, send it back to the same developer agent (SendMessage) with the reviewer's reason, then review again.
6. **Memory.** Append a dated state line to `feedback_orchestrator-role.md` (HEAD, what was done, what's next, next free ids) and update its MEMORY.md index line.
7. **Report.** A few lines: what shipped (commit), test counts, any human test for the owner, and the suggested next round.
