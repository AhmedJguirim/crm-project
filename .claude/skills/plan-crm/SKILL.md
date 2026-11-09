---
name: plan-crm
description: Run the CRM planner (the crm-planner subagent): turn code_review reports, roadmap steps or an owner-reported problem into precise, granular tickets in tickets/pending (plan, Decisions, Gherkin tests). Use as /plan-crm <prompt>, e.g. "/plan-crm next ticket" or "/plan-crm the new organization page has no way back, verify and ticket it".
argument-hint: "<what to plan, e.g. next ticket>"
context: fork
agent: crm-planner
disable-model-invocation: true
---

The owner asks the CRM planner:

$ARGUMENTS

If no request was given, write ONE ticket: take the next item (open security findings, then bugs, then the active roadmap's next steps), skipping anything already ticketed, done or parked.

If the owner's message contains answers to questions you asked in an earlier run, apply them as settled decisions and write the tickets that were waiting on them.

Follow your planner instructions: start by reading `project_current-state.md` and `project_crm-product-decisions.md`, verify the current code before writing, ask (or return) the open product questions in one batch, write the tickets, update your memory, then give the owner the short report described under "Your reply".
