These SSE response bodies come from Prism's Anthropic streaming fixtures at revision
`5d6cc65b80b19cf3f22744703ac0c727b68cdca8`, matching the installed SDK version.

Source: https://github.com/prism-php/prism/tree/5d6cc65b80b19cf3f22744703ac0c727b68cdca8/tests/Fixtures/anthropic

The bodies are unchanged. The tests pass them through the installed provider parser
and agent engine to exercise incremental text, thinking and tool-call events.
