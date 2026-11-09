=== Multisite Migrate – Network Backup, Restore, Clone & Migration ===
Contributors: rosenheinrich, phillipro
Tags: multisite, backup, restore, migration, clone
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.25
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free multisite backup plugin: back up, restore and migrate a whole network or a single site. Chunked jobs for shared hosting. No license key.

== Description ==

Multisite Migrate backs up, restores and migrates a whole WordPress multisite network, every subsite, shared tables and files, for free. It works on single sites too. Jobs run in chunks, so shared-hosting time limits do not kill a backup. Extracting one subsite to a standalone site is free as well. Pro adds cloud schedules, cloud storage, staging push and whole-network split.

**How it works:** 1) Click *Back up now*. It starts right away with safe defaults. 2) Click *Download* next to the archive to keep a copy. 3) To move, install the plugin on the new site, open *Move site*, choose the file and click *Restore*. Web addresses are updated automatically. Server details such as the database engine, chunk sizes and AI agents stay hidden until you turn on *Show expert settings*.

= Video walkthrough =

A short tour of the free plugin: setup, your first archive, import and health checks.

https://www.youtube.com/watch?v=olr5PbskjS0

= Free features =

Everything in this list works without an account, a trial or usage limits.

* **Whole-network backup and restore** from Network Admin: every subsite, the shared tables and all files.
* **Single-site installs** work the same way: full archive, restore, local migration and search & replace.
* **Chunked, resumable jobs** over HTTP or WP-Cron that keep going under tight PHP time limits on shared and managed hosting.
* **Scoped archives:** full network, filtered network, one subsite, or only media, plugins or themes where supported.
* **One-click restore** of any archive the plugin created, from the dashboard.
* **Local export and import** to move or clone a site to another WordPress install, for example for a staging copy.
* **Search & replace** for URLs and paths during import, including serialized data.
* **Extract one subsite to a standalone site:** restore a single site from a network archive onto a normal single-site install.
* **Subsite self-service:** site administrators with `manage_options` can save and restore their own subsite.
* **One local schedule** (daily, weekly or monthly) stored on your server.
* **AES-256 encryption** for archives, if you want it.
* **Email notifications** for manual and scheduled jobs, restores and imports.
* **Health dashboard** with hosting checks and job status.
* Accessible, mobile-friendly admin screens (WCAG 2.2 AA target).

Full guides: [documentation](https://multisitemigrate.rosenheinrich.com/docs/?utm_source=wprepo&utm_medium=link&utm_content=docs&utm_campaign=multisite_migrate_free).

= Paid plugin: Plus and Pro plans =

Paid features ship in a separate plugin, **Multisite Migrate Plus & Pro**, from our website. You never need it for anything in the free list above. It comes in two plans:

* **Plus:** cloud, multiple and incremental schedules; cloud storage on your own Google Drive, Dropbox, Amazon S3 or SFTP; per-site access with subsite seats; extended AI abilities.
* **Pro:** everything in Plus, plus remote server-to-server import, push and pull between two sites, an installer for empty servers without WordPress, staging push, WP-CLI commands, whole-network split into one archive per site, and priority support.

[Compare plans](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=compare_plans&utm_campaign=multisite_migrate_free).

= AI tools and MCP =

Connect Claude, Cursor, ChatGPT or similar AI clients through the WordPress Abilities API and an MCP adapter. This needs WordPress 6.9 or newer; everything else works from WordPress 6.2. The free AI tools need no account or key:

* **Read:** job status, archive list, health with a recommended next step, and the last error.
* **Write:** start a backup, only after an explicit confirm step.

The paid plans add more AI abilities for automation and cloud destinations. See the [AI connection guide](https://multisitemigrate.rosenheinrich.com/docs-connect-ai/?utm_source=wprepo&utm_medium=link&utm_content=mcp_connect&utm_campaign=multisite_migrate_free).

= Translations =

Help translate the plugin and this page on [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/rosenheinrich-multisite-migrate/). The [Polyglots handbook](https://make.wordpress.org/polyglots/handbook/translating/how-to-translate/) explains how to start.

= Source =

Browse the free plugin source and open issues on [GitHub](https://github.com/Rosenheinrich-Sofware-Solutions/rosenheinrich-multisite-migrate?utm_source=wprepo&utm_medium=readme&utm_campaign=multisite_migrate_free&utm_content=github_source). Installs and updates stay on WordPress.org.

== External Services ==

The free plugin does not phone home by default. It contacts external services only when you opt in or submit something yourself.

= Rosenheinrich portal (multisitemigrate.rosenheinrich.com) =

* **Usage telemetry (opt-in, unticked by default):** setup screen or Settings → Privacy. Sends a pseudonymous install ID, site hash (not your URL), plugin/WordPress/PHP versions, setup choices, and sanitized backup/import/restore outcomes (including whether it was the first backup and a coarse time since activation) — never backup files or database content. While the plugin is active, an environment snapshot is sent about once a day. If you opted in, anonymous deactivation and uninstall signals are recorded when the plugin is deactivated or deleted.
* **Product emails (opt-in, unticked by default):** setup screen only. Sends your admin email, name, site URL, site title, and version info to subscribe you to product updates.
* **Feedback (explicit submit):** in-plugin feedback or deactivate survey. Sends your message, rating/reason, versions, and site hash; with the deactivate survey also a short summary of recent plugin errors (no file paths or email addresses); a contact email only if you type one in on deactivate.

Privacy Policy: https://multisitemigrate.rosenheinrich.com/privacy-policy/
Terms (paid Plus and Pro plans only; the free plugin is GPLv2): https://multisitemigrate.rosenheinrich.com/terms-of-service/

= GitHub (optional) =

If you install the MCP Adapter from AI Agents, the plugin fetches the latest release from api.github.com (WordPress/mcp-adapter). Admin action only.

= Paid plugin only (not used by Free) =

License validation and Google Drive OAuth use the same portal; see the Privacy Policy.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it from Plugins → Add New.
2. Activate it through the Plugins screen. On a network you can network-activate it.
3. Open **Multisite Migrate** in the admin menu and create your first archive.
4. Optional: read the [documentation](https://multisitemigrate.rosenheinrich.com/docs/?utm_source=wprepo&utm_medium=link&utm_content=docs&utm_campaign=multisite_migrate_free) for large sites and shared hosting.

== Frequently Asked Questions ==

= Does this work on a normal single-site WordPress install? =

Yes. Full archive, restore, local migration and search & replace all work on single-site installs. When you run a network, the whole-network backup from Network Admin is included too, with no paid add-on.

= Is the whole network really included for free? =

Yes. Saving and restoring the whole network from Network Admin is part of the free plugin, with no key or upgrade. Site administrators with the `manage_options` capability can also save and restore their own subsite from the site dashboard. Delegating subsite access with seat limits (Settings → Site access) is part of the paid plugin.

= Can I turn one subsite into its own standalone site? =

Yes, for free. Create a network archive, install WordPress and this plugin on the destination single site, import the archive and choose *Extract one site as standalone*. The plugin restores that site's tables, users and media and removes the network settings. All user accounts from the network are copied. Accounts without a role on that site get no role there and can be deleted under *Users*. Splitting a whole network into one archive per site is part of the Pro plan.

= Do I need an account or license key? =

No. The free plugin is fully functional with no key, no trial and no time or usage limits.

= Does Free include scheduled backups? =

Yes. Free includes one local schedule (daily, weekly or monthly) stored on your server. Multiple schedules, cloud destinations and incremental runs are part of the paid Plus and Pro plans.

= Where are archives stored? =

In the free plugin, archives stay in your WordPress uploads folder on your own server. Cloud destinations are part of the paid plugin.

= How do I restore? =

Open Multisite Migrate → Backups, pick a completed archive and click *Restore*.

= How do I migrate a site to a new host? =

Create an archive, install the plugin on the destination, then import it and restore with search & replace for the new URL. For a blank server without WordPress, the Pro plan includes an installer for empty servers.

= Will it finish on shared hosting? =

Yes. Jobs run in small chunks that resume across requests, so short PHP time limits and gateway timeouts do not stop them. They also avoid holding one long request open, which keeps your site responsive.

= Do I need coding skills? =

No. Everything runs from the WordPress admin screens. The Pro plan adds optional WP-CLI commands for automation.

= Which AI / MCP features are free? =

Job status, archive list, health with a recommended next step, the last error, and starting a backup after an explicit confirm step. Connect Claude, Cursor, ChatGPT or a similar client via MCP, with no account or key. You do not need any AI client to use the plugin.

= How can I help translate the plugin? =

Translations live on WordPress.org, so you do not need to edit files in the plugin ZIP.

1. Open https://translate.wordpress.org/projects/wp-plugins/rosenheinrich-multisite-migrate/
2. Choose your language (Stable, Development, and both Readme columns).
3. Suggest translations for untranslated strings.
4. New to translating WordPress? Read https://make.wordpress.org/polyglots/handbook/translating/how-to-translate/

Approved Stable translations (about 90% Current) become an automatic language pack for that locale.

= How do I report a security issue? =

Report security issues to phillip@rosenheinrich.com; we aim to respond within 72 hours. Please do not post security problems in the public support forum or in public GitHub issues.

== Changelog ==

= 1.2.25 =
* Fix: Restoring a subsite backup as a standalone site while keeping its original table prefix no longer leaves users without a role.
* New: Extract one site from a network backup into a standalone WordPress site, now free. Choose "Extract one site as standalone" when you restore a network backup on a single-site install.
* New: Optional backup before updates, one-click rollback after restore, resume of interrupted jobs, and a server check before you start.
* New: A simpler start. "Back up now" runs in one click, and Download and Restore sit right next to each backup.
* Improvement: Restoring or moving a site asks fewer questions, and technical settings moved to an optional Expert mode.
* Improvement: Clearer wording for single sites and networks. Product emails and usage data stay off unless you opt in.
* Improvement: Better deactivation feedback.
* Fix: Admin redirect after activation.
* Fix: Various bug fixes and stability improvements.

= 1.2.24 – 2026-10-27 =
* Fix: SQL export checkpoint recovery on resume and fewer false activity-log errors from gateway timeouts and background polling.

= 1.2.23 – 2026-10-26 =
* Security fixes.

Older entries are in changelog.txt, which ships with the plugin, and the full history is published at [https://multisitemigrate.rosenheinrich.com/changelog/](https://multisitemigrate.rosenheinrich.com/changelog/).

== Upgrade Notice ==

= 1.2.25 =
Simpler backup, restore and move screens, plus bug fixes. Recommended update.

= 1.2.24 =
Bug fixes for backup SQL resume and activity log noise. Recommended update.

= 1.2.23 =
Security fixes. Recommended update.

== Screenshots ==

1. Backups: protect the network — list complete archives with status, size, scope, and filters.
2. Backup wizard: choose Full profile, DAF or ZIP archive format, and optional WordPress core files.
3. AI Agents (MCP): connect Cursor, Claude, or ChatGPT to backup status, health, and archives.
4. Import & Restore: upload a .zip, .daf, or .venc backup; large files upload in chunks.
5. Schedules: one local Free schedule — interval, time, full profile, and retention.
6. Settings: encrypt archives at rest, safety snapshot before restore, exclusions, retention, and database export mode.
7. Activity Log: filter plugin events by type and date; on-disk logs rotate automatically.
8. Health: hosting checks for disk space, PHP limits, ZipArchive, mysqldump, and the backup engine.
