---
name: review-crm
description: Run the CRM code reviewer (the crm-reviewer subagent): review tickets in tickets/to-be-reviewed, run a global review of an area, write code_review reports or plans, read human-test results. Use as /review-crm <prompt>, e.g. "/review-crm review" or "/review-crm review the companies part".
argument-hint: "<what to do, e.g. review>"
context: fork
agent: crm-reviewer
disable-model-invocation: true
---

The owner asks the CRM reviewer:

$ARGUMENTS

If no request was given, treat it as "review": review every ticket in `tickets/to-be-reviewed/`.

Follow your reviewer instructions: start by reading the memory index and the review state, then do the task, then give the owner the short report described under "Your reply".
