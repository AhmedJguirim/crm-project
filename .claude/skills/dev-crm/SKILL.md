---
name: dev-crm
description: Run the CRM developer (the crm-developer subagent): implement the tickets in tickets/pending, or fix the ones in tickets/tested-KO, with tests, mutation checks, full suite, Pint and a dev comment. Use as /dev-crm <prompt>, e.g. "/dev-crm work the pending tickets" or "/dev-crm fix the KO ticket".
argument-hint: "<what to do, e.g. work the pending tickets>"
context: fork
agent: crm-developer
disable-model-invocation: true
---

The owner asks the CRM developer:

$ARGUMENTS

If no request was given, treat it as "work the pending tickets": take the tickets in `tickets/tested-KO/` first (fix them), then those in `tickets/pending/`, one at a time.

Follow your developer instructions: start by reading the memory index and the developer notes, then do the task one ticket at a time, then give the owner the short report described under "Your reply".
