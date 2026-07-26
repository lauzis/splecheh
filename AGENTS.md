# AGENTS.md

Working notes for AI agents (and humans) contributing to this plugin.

## Change log

The full history lives in [CHANGELOG.md](CHANGELOG.md) — every release, newest first.
`README.md` carries **only the most recent release**, under its own `## Change log`
heading with a link to the full file.

When you add a change log entry:

1. Add it to the newest version's section in `CHANGELOG.md` (heading level `##`, format `## --- 0.25.1 ---`).
2. Mirror that same section into `README.md`, **replacing** the entry that was there.
   The README must never accumulate a second version section.
3. Bump `Version:` in the `splecheh.php` plugin header and the `SPLECHEH_VERSION`
   constant to match the section you are writing under.

Starting a new version means the previous README section is dropped from the README —
it is already preserved in `CHANGELOG.md`, so nothing is lost.

Entries are prose, not one-liners: say what changed, and why it was worth changing.
Name the functions/classes/files involved so the entry is a usable pointer later.
