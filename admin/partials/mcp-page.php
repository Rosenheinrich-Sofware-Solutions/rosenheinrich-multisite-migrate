<?php

if (!defined('ABSPATH')) {
    exit;
}

?>
<div class="mm-mcp-page" id="mm-mcp-app">
	<section class="mm-premium-panel mm-mcp-section mm-mcp-section--intro" aria-label="<?php echo esc_attr__('AI Agents overview', 'rosenheinrich-multisite-migrate'); ?>">
		<p class="mm-mcp-lead">
			<?php echo esc_html__('Ask Cursor, Claude, or ChatGPT about Multisite backup status, health, and archives — without leaving your editor. Backups start only after you confirm.', 'rosenheinrich-multisite-migrate'); ?>
		</p>
		<p class="mm-mcp-prompts-label"><?php echo esc_html__('Example prompts', 'rosenheinrich-multisite-migrate'); ?></p>
		<ul class="mm-mcp-prompts">
			<li><code><?php echo esc_html__('What is the backup job status?', 'rosenheinrich-multisite-migrate'); ?></code></li>
			<li><code><?php echo esc_html__('List recent archives', 'rosenheinrich-multisite-migrate'); ?></code></li>
			<li><code><?php echo esc_html__('Why did the last backup fail?', 'rosenheinrich-multisite-migrate'); ?></code></li>
			<li><code><?php echo esc_html__('Start a full network backup', 'rosenheinrich-multisite-migrate'); ?></code></li>
		</ul>
	</section>

	<div class="mm-mcp-layout">
		<section class="mm-premium-panel mm-mcp-section mm-mcp-section--setup" aria-labelledby="mm-mcp-steps-heading">
			<div class="mm-premium-panel-header">
				<h2 id="mm-mcp-steps-heading" class="mm-section-heading"><?php echo esc_html__('Setup', 'rosenheinrich-multisite-migrate'); ?></h2>
				<p class="mm-mcp-stepper-status" id="mm-mcp-stepper-status" aria-live="polite"></p>
			</div>
			<ol class="mm-mcp-steps">
				<li id="mm-mcp-step-1" class="mm-mcp-step">
					<div class="mm-mcp-step__head">
						<span class="mm-mcp-step__title">
							<span class="mm-mcp-step__num" aria-hidden="true">1</span>
							<span class="mm-mcp-step__label"><?php echo esc_html__('Abilities', 'rosenheinrich-multisite-migrate'); ?></span>
						</span>
						<span class="mm-mcp-step-state mm-status-pill" data-step="1"></span>
					</div>
					<p class="description" id="mm-mcp-step-1-desc"></p>
				</li>
				<li id="mm-mcp-step-2" class="mm-mcp-step">
					<div class="mm-mcp-step__head">
						<span class="mm-mcp-step__title">
							<span class="mm-mcp-step__num" aria-hidden="true">2</span>
							<span class="mm-mcp-step__label"><?php echo esc_html__('MCP Adapter', 'rosenheinrich-multisite-migrate'); ?></span>
						</span>
						<span class="mm-mcp-step-state mm-status-pill" data-step="2"></span>
					</div>
					<p class="description"><?php echo esc_html__('Install the official WordPress MCP Adapter plugin (Network Activate on Multisite).', 'rosenheinrich-multisite-migrate'); ?></p>
					<p class="mm-mcp-step__actions">
						<button type="button" class="button button-primary" id="mm-mcp-install-adapter"><?php echo esc_html__('Install MCP Adapter', 'rosenheinrich-multisite-migrate'); ?></button>
					</p>
					<p class="mm-mcp-install-error description" id="mm-mcp-install-error" hidden></p>
				</li>
				<li id="mm-mcp-step-3" class="mm-mcp-step">
					<div class="mm-mcp-step__head">
						<span class="mm-mcp-step__title">
							<span class="mm-mcp-step__num" aria-hidden="true">3</span>
							<span class="mm-mcp-step__label"><?php echo esc_html__('Application password', 'rosenheinrich-multisite-migrate'); ?></span>
						</span>
						<span class="mm-mcp-step-state mm-status-pill" data-step="3"></span>
					</div>
					<p class="description" id="mm-mcp-app-pw-desc"></p>
					<div class="mm-mcp-app-pw-help" id="mm-mcp-app-pw-help" hidden>
						<p class="mm-mcp-app-pw-help__title"><?php echo esc_html__('What to do', 'rosenheinrich-multisite-migrate'); ?></p>
						<ol class="mm-mcp-howto">
							<li><?php echo esc_html__('Put the site on HTTPS with a valid certificate. WordPress blocks Application Passwords on plain HTTP.', 'rosenheinrich-multisite-migrate'); ?></li>
							<li><?php echo esc_html__('In security plugins (Wordfence, Solid Security, etc.), turn Application Passwords back on if they were disabled.', 'rosenheinrich-multisite-migrate'); ?></li>
							<li><?php echo esc_html__('Ask your host whether a must-use plugin or filter forces Application Passwords off — that must be removed.', 'rosenheinrich-multisite-migrate'); ?></li>
							<li><?php echo esc_html__('Reload this page. When the status is no longer Disabled, click Generate Application Password and paste it into your AI client config.', 'rosenheinrich-multisite-migrate'); ?></li>
							<li><?php echo esc_html__('ChatGPT only: use the ChatGPT tab and OAuth instead — that path does not need an Application Password.', 'rosenheinrich-multisite-migrate'); ?></li>
						</ol>
					</div>
					<p class="mm-mcp-step__actions">
						<button type="button" class="button button-primary" id="mm-mcp-generate-app-pw"><?php echo esc_html__('Generate Application Password', 'rosenheinrich-multisite-migrate'); ?></button>
					</p>
					<div class="mm-mcp-app-passwords" id="mm-mcp-app-pw-list-wrap">
						<h3 class="mm-section-heading"><?php echo esc_html__('Existing Application Passwords', 'rosenheinrich-multisite-migrate'); ?></h3>
						<ul id="mm-mcp-app-pw-list" aria-live="polite"></ul>
					</div>
					<p class="mm-mcp-secret" id="mm-mcp-app-pw-display" hidden></p>
				</li>
				<li id="mm-mcp-step-4" class="mm-mcp-step">
					<div class="mm-mcp-step__head">
						<span class="mm-mcp-step__title">
							<span class="mm-mcp-step__num" aria-hidden="true">4</span>
							<span class="mm-mcp-step__label"><?php echo esc_html__('Connection test', 'rosenheinrich-multisite-migrate'); ?></span>
						</span>
						<span class="mm-mcp-step-state mm-status-pill" data-step="4"></span>
					</div>
					<p class="description"><?php echo esc_html__('Sign in with your Application Password and confirm Multisite Migrate abilities are visible.', 'rosenheinrich-multisite-migrate'); ?></p>
					<p class="mm-mcp-step__actions">
						<button type="button" class="button" id="mm-mcp-test-connection"><?php echo esc_html__('Test connection', 'rosenheinrich-multisite-migrate'); ?></button>
					</p>
					<p class="mm-mcp-test-result" id="mm-mcp-test-result" aria-live="polite"></p>
				</li>
			</ol>
		</section>

		<section class="mm-premium-panel mm-mcp-section mm-mcp-section--connect" aria-labelledby="mm-mcp-clients-heading">
			<div class="mm-premium-panel-header">
				<h2 id="mm-mcp-clients-heading" class="mm-section-heading"><?php echo esc_html__('Connect to AI client', 'rosenheinrich-multisite-migrate'); ?></h2>
			</div>
			<div class="mm-nav-tabs-scroll mm-mcp-tabs-scroll">
				<div class="mm-nav-tabs" role="tablist" aria-label="<?php echo esc_attr__('AI clients', 'rosenheinrich-multisite-migrate'); ?>">
					<button type="button" class="mm-nav-tab is-active" role="tab" aria-selected="true" data-tab="claude-desktop">Claude Desktop</button>
					<button type="button" class="mm-nav-tab" role="tab" aria-selected="false" data-tab="claude-code">Claude Code</button>
					<button type="button" class="mm-nav-tab" role="tab" aria-selected="false" data-tab="cursor">Cursor</button>
					<button type="button" class="mm-nav-tab" role="tab" aria-selected="false" data-tab="vscode">VS Code</button>
					<button type="button" class="mm-nav-tab" role="tab" aria-selected="false" data-tab="gemini">Gemini</button>
					<button type="button" class="mm-nav-tab" role="tab" aria-selected="false" data-tab="windsurf">Windsurf</button>
					<button type="button" class="mm-nav-tab" role="tab" aria-selected="false" data-tab="chatgpt">ChatGPT</button>
				</div>
			</div>

			<div class="mm-mcp-tab-panels">
				<div class="mm-mcp-tab-panel is-active" data-panel="claude-desktop" role="tabpanel">
					<p><?php echo esc_html__('Paste this JSON into Claude Desktop MCP config (stdio via mcp-wordpress-remote).', 'rosenheinrich-multisite-migrate'); ?></p>
					<ol class="mm-mcp-howto">
						<li><?php echo esc_html__('Finish Setup and generate an Application Password, then Copy.', 'rosenheinrich-multisite-migrate'); ?></li>
						<li><?php echo esc_html__('Open claude_desktop_config.json (macOS: ~/Library/Application Support/Claude/ — Windows: %APPDATA%\\Claude\\).', 'rosenheinrich-multisite-migrate'); ?></li>
						<li><?php echo esc_html__('Merge the mcpServers block, replace {application-password}, save, fully quit and reopen Claude Desktop.', 'rosenheinrich-multisite-migrate'); ?></li>
					</ol>
					<pre class="mm-mcp-snippet" id="mm-mcp-snip-stdio"></pre>
					<p class="mm-mcp-step__actions">
						<button type="button" class="button mm-mcp-copy" data-copy="mm-mcp-snip-stdio"><?php echo esc_html__('Copy', 'rosenheinrich-multisite-migrate'); ?></button>
					</p>
				</div>
				<div class="mm-mcp-tab-panel" data-panel="claude-code" role="tabpanel" hidden>
					<p><?php echo esc_html__('Run this once in a terminal where the Claude Code CLI is installed.', 'rosenheinrich-multisite-migrate'); ?></p>
					<ol class="mm-mcp-howto">
						<li><?php echo esc_html__('Generate an Application Password in Setup, then Copy the command.', 'rosenheinrich-multisite-migrate'); ?></li>
						<li><?php echo esc_html__('Paste into Terminal / PowerShell, replace the password placeholder, then run.', 'rosenheinrich-multisite-migrate'); ?></li>
						<li><?php echo esc_html__('Confirm server multisite-migrate-site appears in Claude Code.', 'rosenheinrich-multisite-migrate'); ?></li>
					</ol>
					<pre class="mm-mcp-snippet" id="mm-mcp-snip-claude-code"></pre>
					<p class="mm-mcp-step__actions">
						<button type="button" class="button mm-mcp-copy" data-copy="mm-mcp-snip-claude-code"><?php echo esc_html__('Copy', 'rosenheinrich-multisite-migrate'); ?></button>
					</p>
				</div>
				<div class="mm-mcp-tab-panel" data-panel="cursor" role="tabpanel" hidden>
					<p><?php echo esc_html__('Add this JSON to Cursor MCP config.', 'rosenheinrich-multisite-migrate'); ?></p>
					<ol class="mm-mcp-howto">
						<li><?php echo esc_html__('Finish Setup, generate an Application Password, then Copy.', 'rosenheinrich-multisite-migrate'); ?></li>
						<li><?php echo esc_html__('Project: .cursor/mcp.json in the repo root. Global: ~/.cursor/mcp.json (Windows: %USERPROFILE%\\.cursor\\mcp.json).', 'rosenheinrich-multisite-migrate'); ?></li>
						<li><?php echo esc_html__('Or Cursor Settings → Tools & MCP → New MCP Server.', 'rosenheinrich-multisite-migrate'); ?></li>
						<li><?php echo esc_html__('Merge only multisite-migrate-site if mcpServers already exists. Replace {application-password}.', 'rosenheinrich-multisite-migrate'); ?></li>
						<li><?php echo esc_html__('Save, refresh the server in Tools & MCP, then ask about backup status in Agent chat.', 'rosenheinrich-multisite-migrate'); ?></li>
					</ol>
					<pre class="mm-mcp-snippet" id="mm-mcp-snip-cursor"></pre>
					<p class="mm-mcp-step__actions">
						<button type="button" class="button mm-mcp-copy" data-copy="mm-mcp-snip-cursor"><?php echo esc_html__('Copy', 'rosenheinrich-multisite-migrate'); ?></button>
					</p>
				</div>
				<div class="mm-mcp-tab-panel" data-panel="vscode" role="tabpanel" hidden>
					<p><?php echo esc_html__('Add this JSON for VS Code / GitHub Copilot MCP.', 'rosenheinrich-multisite-migrate'); ?></p>
					<ol class="mm-mcp-howto">
						<li><?php echo esc_html__('Generate an Application Password in Setup, then Copy.', 'rosenheinrich-multisite-migrate'); ?></li>
						<li><?php echo esc_html__('Workspace: .vscode/mcp.json. Or Command Palette → “MCP: Open User Configuration”.', 'rosenheinrich-multisite-migrate'); ?></li>
						<li><?php echo esc_html__('Merge under mcpServers, replace {application-password}, save, reload the window.', 'rosenheinrich-multisite-migrate'); ?></li>
					</ol>
					<pre class="mm-mcp-snippet" id="mm-mcp-snip-vscode"></pre>
					<p class="mm-mcp-step__actions">
						<button type="button" class="button mm-mcp-copy" data-copy="mm-mcp-snip-vscode"><?php echo esc_html__('Copy', 'rosenheinrich-multisite-migrate'); ?></button>
					</p>
				</div>
				<div class="mm-mcp-tab-panel" data-panel="gemini" role="tabpanel" hidden>
					<p><?php echo esc_html__('Add this under mcpServers in Gemini CLI settings.', 'rosenheinrich-multisite-migrate'); ?></p>
					<ol class="mm-mcp-howto">
						<li><?php echo esc_html__('Generate an Application Password in Setup, then Copy.', 'rosenheinrich-multisite-migrate'); ?></li>
						<li><?php echo esc_html__('Edit ~/.gemini/settings.json (create if missing).', 'rosenheinrich-multisite-migrate'); ?></li>
						<li><?php echo esc_html__('Merge the entry, replace {application-password}, restart Gemini CLI.', 'rosenheinrich-multisite-migrate'); ?></li>
					</ol>
					<pre class="mm-mcp-snippet" id="mm-mcp-snip-gemini"></pre>
					<p class="mm-mcp-step__actions">
						<button type="button" class="button mm-mcp-copy" data-copy="mm-mcp-snip-gemini"><?php echo esc_html__('Copy', 'rosenheinrich-multisite-migrate'); ?></button>
					</p>
				</div>
				<div class="mm-mcp-tab-panel" data-panel="windsurf" role="tabpanel" hidden>
					<p><?php echo esc_html__('Paste this into Windsurf MCP settings.', 'rosenheinrich-multisite-migrate'); ?></p>
					<ol class="mm-mcp-howto">
						<li><?php echo esc_html__('Generate an Application Password in Setup, then Copy.', 'rosenheinrich-multisite-migrate'); ?></li>
						<li><?php echo esc_html__('Windsurf Settings → Cascade / MCP (or edit the MCP config JSON on disk).', 'rosenheinrich-multisite-migrate'); ?></li>
						<li><?php echo esc_html__('Add multisite-migrate-site, replace {application-password}, restart Windsurf.', 'rosenheinrich-multisite-migrate'); ?></li>
					</ol>
					<pre class="mm-mcp-snippet" id="mm-mcp-snip-windsurf"></pre>
					<p class="mm-mcp-step__actions">
						<button type="button" class="button mm-mcp-copy" data-copy="mm-mcp-snip-windsurf"><?php echo esc_html__('Copy', 'rosenheinrich-multisite-migrate'); ?></button>
					</p>
				</div>
				<div class="mm-mcp-tab-panel" data-panel="chatgpt" role="tabpanel" hidden>
					<p><?php echo esc_html__('ChatGPT Developer Mode connectors require OAuth (not Application Passwords alone).', 'rosenheinrich-multisite-migrate'); ?></p>
					<p class="mm-mcp-step__actions">
						<button type="button" class="button button-primary" id="mm-mcp-generate-oauth"><?php echo esc_html__('Generate ChatGPT OAuth credentials', 'rosenheinrich-multisite-migrate'); ?></button>
					</p>
					<div id="mm-mcp-oauth-fields" class="mm-mcp-oauth-fields" hidden>
						<label><?php echo esc_html__('MCP Server URL', 'rosenheinrich-multisite-migrate'); ?>
							<input type="text" class="widefat" id="mm-mcp-oauth-mcp-url" readonly />
						</label>
						<label><?php echo esc_html__('Client ID', 'rosenheinrich-multisite-migrate'); ?>
							<input type="text" class="widefat" id="mm-mcp-oauth-client-id" readonly />
						</label>
						<label><?php echo esc_html__('Client Secret', 'rosenheinrich-multisite-migrate'); ?>
							<input type="text" class="widefat" id="mm-mcp-oauth-client-secret" readonly />
						</label>
						<label><?php echo esc_html__('Authorization URL', 'rosenheinrich-multisite-migrate'); ?>
							<input type="text" class="widefat" id="mm-mcp-oauth-auth-url" readonly />
						</label>
						<label><?php echo esc_html__('Token URL', 'rosenheinrich-multisite-migrate'); ?>
							<input type="text" class="widefat" id="mm-mcp-oauth-token-url" readonly />
						</label>
						<label><?php echo esc_html__('Discovery URL', 'rosenheinrich-multisite-migrate'); ?>
							<input type="text" class="widefat" id="mm-mcp-oauth-discovery" readonly />
						</label>
						<ol class="mm-mcp-howto">
							<li><?php echo esc_html__('ChatGPT → Settings → Apps / Connectors → enable Developer Mode', 'rosenheinrich-multisite-migrate'); ?></li>
							<li><?php echo esc_html__('Create connector → Auth = OAuth → paste MCP Server URL, Client ID/Secret, Authorization URL, Token URL', 'rosenheinrich-multisite-migrate'); ?></li>
							<li><?php echo esc_html__('Authorize on this WordPress site when prompted. Copy the Client Secret now (shown once).', 'rosenheinrich-multisite-migrate'); ?></li>
						</ol>
						<p class="description"><?php echo esc_html__('ChatGPT Plus/Team + Developer Mode is a client requirement.', 'rosenheinrich-multisite-migrate'); ?></p>
					</div>
					<p class="mm-mcp-oauth-msg" id="mm-mcp-oauth-msg" aria-live="polite"></p>
					<div class="mm-mcp-oauth-clients">
						<h3 class="mm-section-heading"><?php echo esc_html__('OAuth clients', 'rosenheinrich-multisite-migrate'); ?></h3>
						<ul id="mm-mcp-oauth-client-list"></ul>
						<p class="mm-mcp-step__actions">
							<button type="button" class="button" id="mm-mcp-refresh-oauth-clients"><?php echo esc_html__('Refresh client list', 'rosenheinrich-multisite-migrate'); ?></button>
						</p>
					</div>
				</div>
			</div>
		</section>
	</div>

	<section class="mm-premium-panel mm-mcp-section mm-mcp-section--abilities" aria-labelledby="mm-mcp-abilities-heading">
		<div class="mm-premium-panel-header">
			<h2 id="mm-mcp-abilities-heading" class="mm-section-heading"><?php echo esc_html__('Registered abilities', 'rosenheinrich-multisite-migrate'); ?></h2>
		</div>
		<ul id="mm-mcp-abilities-list" class="mm-mcp-abilities"></ul>
		<?php
		if (class_exists('Rmmigrate_Pro_Hints')) {
			Rmmigrate_Pro_Hints::render('mcp_pro_tools', array(
				'title'     => __('Cloud schedules, restore & staging abilities (Pro)', 'rosenheinrich-multisite-migrate'),
				'text'      => __('Free includes status, archives, activity log, cancel, local backup with excludes, and one local schedule. Pro adds schedule CRUD for cloud destinations, restore, staging, and empty-server tools for AI clients.', 'rosenheinrich-multisite-migrate'),
				'cta_label' => __('Explore Pro AI tools', 'rosenheinrich-multisite-migrate'),
				'placement' => 'mcp_tab',
			));
		}
		?>
	</section>
</div>
