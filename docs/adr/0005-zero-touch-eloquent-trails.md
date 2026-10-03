# ADR 0005: Zero-touch Eloquent trails

Status: Accepted

When Eloquent is installed, Eagle Eye registers an `activityTrail()` builder macro. Persisted models gain subject and actor trails without traits or imports.

Eloquent remains optional. Registration is guarded at runtime, preserving the minimal core dependency graph.
