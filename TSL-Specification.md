# Task Specification Language (TSL)

## Version: 1.0  
**Date:** February  2026  
**Status:** Official – Permanent Method of Work  

This document defines the **Task Specification Language (TSL)**, a YAML-based format for writing precise, unambiguous coding task specifications.  
The purpose of TSL is to:

- Eliminate ambiguity in what is being asked of developers
- Force tasks to remain small and focused
- Provide objective, testable acceptance criteria that serve as the contract between stakeholders and developers
- Prevent “that’s not what I asked for” disputes by making the specification the single source of truth
- Enable clear accountability: if all acceptance criteria pass and scope rules are respected, the task is correctly implemented

TSL specifications **must** be used for **every** development task. No task may be started without an approved TSL file.

## Core Principles

1. **Single Responsibility**  
   One TSL file = one coherent, deliverable change that can realistically be completed in ≤ 16 hours (medium complexity).

2. **Bounded Scope**  
   Explicit limits on affected components, new endpoints/functions, acceptance criteria, etc. Oversized tasks **must** be split.

3. **Mandatory Structured Sections**  
   Certain sections are required to force complete thinking about inputs, outputs, edge cases, and success conditions.

4. **Testable Acceptance Criteria**  
   Written in **Given-When-Then** format with concrete examples. These are the contractual success conditions.

5. **Complexity Rating with Hard Limits**  
   Tasks rated “complex” are invalid and **must** be split before work begins.

## Specification Structure (YAML Schema)

Every TSL file **must** follow this exact structure. Required fields are marked as `(required)`.

```yaml
spec_version: 1.0                  # (required) Must be 1.0
task_id: TSK-YYYY-XXXX             # (required) Unique identifier (e.g., TSK-2026-0023)
title: Short clear title           # (required) ≤ 70 characters

user_story: >-                     # (recommended) As a [role], I want [feature] so that [benefit]
  As an admin, I want to ...

description: >-                    # (required) Detailed narrative, ≤ 400 words
  The system must ...

scope:                             # (required) Enforces size limits
  max_complexity: medium           # (required) simple | medium | complex (complex = invalid, must split)
  affected_components:             # (required) Max according to complexity table
    - backend/users
    - api/v1/users
    - admin-ui/import
  new_endpoints: 1                 # (required) Count of new API endpoints/routes
  new_database_tables: 0           # (required) Count of new DB tables/collections
  estimated_hours: 10-14           # (required) Realistic range for planning

preconditions:                     # (required) What must already exist
  - Authentication system in place
  - User model with fields: email, first_name, last_name, role

functional_requirements:           # (required) Max according to complexity table
  - REQ-001: Description...
  - REQ-002: ...

acceptance_criteria:               # (required) Max according to complexity table
  - id: AC-001
    scenario: Short description
    given:
      - Condition 1
      - Condition 2
    when:
      - Action performed
    then:
      - Expected outcome 1
      - Expected outcome 2
    examples:                      # (optional but strongly recommended for clarity)
      input_csv: |
        email,first_name,last_name,role
        alice@example.com,Alice,Smith,editor
      expected_users_created: 1

  # Additional criteria...

non_functional_requirements:       # (optional but encouraged)
  performance:
    - Process ≤ 10,000 rows in < 30 seconds
  security:
    - Only admins can access
  logging:
    - Log each action

edge_cases:                        # (required if any are known; otherwise empty list)
  - Empty file → Show error "File is empty"
  - Missing headers → Reject file

dependencies:                      # (optional) Other task IDs that must be completed first
  - TSK-2026-0001

notes:                             # (optional) Attachments, clarifications, UI references
  - UI mockup: import-ui-v2.png
  - Error messages must follow global style guide
```