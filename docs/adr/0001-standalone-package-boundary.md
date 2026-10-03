# ADR 0001: Standalone package boundary

Status: Accepted

Eagle Eye is a standalone Composer package. Runtime code may depend only on PHP and `illuminate/*`. Package source, configuration, language files, and stubs cannot reference host application namespaces.

Host integrations depend on Eagle Eye contracts. Eagle Eye never detects or resolves host classes by name.
