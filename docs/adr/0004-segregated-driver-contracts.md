# ADR 0004: Segregated driver contracts

Status: Accepted

Writing, querying, and maintenance use separate contracts. Every driver writes; query and maintenance capabilities remain explicit.

Presentation code depends on `ActivityRepositoryInterface`, never a concrete backend.
