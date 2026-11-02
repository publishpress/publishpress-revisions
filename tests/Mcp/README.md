# MCP regression tests

`smoke.php` requires a disposable WordPress installation using a database named `revisions_mcp_*`, with Revisions activated and user ID 1 as the fixture administrator. It refuses other database names. Never run it against production data.

Load the released WordPress MCP Adapter separately and pass its extracted directory as the second argument:

```sh
REVISIONS_MCP_MODE=free php tests/Mcp/smoke.php /path/to/wordpress/wp-load.php /path/to/mcp-adapter
```

Use `REVISIONS_MCP_MODE=pro` with the Pro distribution and the current shared Free source for Pro cases. Install declared distribution dependencies, intercept `pre_wp_mail`, and block external HTTP requests. Tests create and modify synthetic posts and settings. The test calls the released Adapter's `McpTool` for execution and checks native storage; it does not establish an external AI-client transport session.

Optional dependency coverage accepts `REVISIONS_MCP_CAPABILITIES_FILE=/path/to/publishpress-capabilities/includes/application-passwords.php`. This executes the real native restriction component with a synthetic application password and checks a restricted site Administrator. It is a dependency-component fixture, not a full Capabilities distribution or HTTP authentication test. Multisite fixtures must network-activate Revisions and verify network-controlled settings separately.
