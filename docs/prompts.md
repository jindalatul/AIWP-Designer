# Prompts

Every prompt ships inside the plugin, in `prompts/`. There is no external prompt
API, and prompt versions are part of plugin releases.

```
prompts/
├── core/      system, safety, template-language, css-rules, content-model
├── design/    brand-analysis, design-system, page-planner, page-designer,
│              responsive-design, visual-polish
├── review/    design-critic, accessibility, performance
└── workflows/ build-page, redesign-page, redesign-section,
               create-design-system, improve-page
```

## Format

```markdown
---
id: page-designer
version: 1
category: design
description: Designs an individual WordPress page.
---

The prompt body…
```

A file without valid frontmatter is not loaded. A prompt name must match
`folder/name`; anything containing `..`, a null byte or an absolute path is
refused, and the resolved path is checked against the prompts directory.

## Workflows

`PromptRegistry::WORKFLOWS` maps each workflow to the prompts it needs and the
context to attach. Nothing unrelated is sent.

| Workflow | Prompts | Context |
|---|---|---|
| `build_page` | system, safety, template-language, content-model, css-rules, page-planner, page-designer, responsive-design, design-critic, build-page | site, capabilities, design system |
| `redesign_page` | system, safety, template-language, content-model, css-rules, page-designer, visual-polish, responsive-design, design-critic, redesign-page | site, capabilities, design system |
| `redesign_section` | system, safety, template-language, css-rules, visual-polish, redesign-section | capabilities, design system |
| `create_design_system` | system, safety, css-rules, brand-analysis, design-system, create-design-system | site, capabilities |
| `improve_page` | system, safety, template-language, css-rules, design-critic, accessibility, performance, improve-page | capabilities, design system |
| `update_content` | system, safety, content-model | capabilities |

Compilation is deterministic: the same workflow and context always produce the
same instructions, which is what makes them snapshot-testable.

## Why `workflow_prepare` exists

An MCP client may never read a server's prompts. Exposing them through
`prompts/list` is not enough to guarantee the AI has them. So the instructions
are handed back by the same call that issues the `workflow_id` every mutating
tool demands. Reading the rules is the only way to get permission to act.

## Editing prompts

Edit the markdown and bump its `version`. Nothing else to do — no build, no cache
to clear. This is the main dial for design quality.
