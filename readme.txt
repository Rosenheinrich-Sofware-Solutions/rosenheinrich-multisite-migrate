=== Rosenheinrich Multisite Migrate – Network & Single Site Backup, Restore & Migration ===
Contributors: rosenheinrich, phillipro
Tags: multisite, site-migration, wordpress-migration, migrate-wordpress, clone-site
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.24
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, restore and move WordPress multisite networks — and single sites — in one click. Free full-network backups, no license key.

== Description ==

= Backup, Restore & Migration built for Multisite — works on single sites too =

Made for WordPress multisite networks first: back up the whole network (every subsite, the shared tables and all files) in one click, restore it, or move it to a new server — free, no license key. Normal single-site installs work the same way.

**How it works:** 1) Click *Back up now* — it starts right away with safe defaults. 2) Click *Download* next to the backup to keep a copy. 3) To move, install the plugin on the new site, open *Move site*, choose the file and click *Restore*; web addresses are updated automatically. Server internals (database engine, chunk sizes, AI agents via MCP) stay out of the way until you turn on *Show expert settings*. [Full network backups](https://multisitemigrate.rosenheinrich.com/features/multisite-networks/?utm_source=wprepo&utm_medium=link&utm_content=multisite_networks&utm_campaign=multisite_migrate_free) from Network Admin stay included in Free; Pro is for cloud / multiple / incremental schedules, cloud storage, empty-server installer, staging, [peer transfer (Push & Pull)](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=peer_transfer&utm_campaign=multisite_migrate_free), WP-CLI, [network split / extract to standalone](https://multisitemigrate.rosenheinrich.com/docs-network-split/?utm_source=wprepo&utm_medium=link&utm_content=docs_network_split&utm_campaign=multisite_migrate_free), and extended AI abilities.

= Video walkthrough =

Short overview of Multisite Migrate Free — setup, backups, import, health, and your first backup:

https://www.youtube.com/watch?v=olr5PbskjS0

No trial, no usage limits. Everything under Free features works without a key. Free includes one local scheduled backup. [Cloud scheduled backups](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=pricing&utm_campaign=multisite_migrate_free), [cloud storage](https://multisitemigrate.rosenheinrich.com/features/cloud-backup/?utm_source=wprepo&utm_medium=link&utm_content=cloud_backup&utm_campaign=multisite_migrate_free), [empty-server migration](https://multisitemigrate.rosenheinrich.com/features/empty-server-migration/?utm_source=wprepo&utm_medium=link&utm_content=empty_server_migration&utm_campaign=multisite_migrate_free), [staging push](https://multisitemigrate.rosenheinrich.com/features/staging/?utm_source=wprepo&utm_medium=link&utm_content=staging&utm_campaign=multisite_migrate_free), and [peer transfer](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=peer_transfer&utm_campaign=multisite_migrate_free) ship in the separate [Multisite Migrate Pro](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=get_pro&utm_campaign=multisite_migrate_free) plugin.

= Why Multisite Migrate =

* **[Full multisite network backups — free](https://multisitemigrate.rosenheinrich.com/features/multisite-networks/?utm_source=wprepo&utm_medium=link&utm_content=multisite_networks&utm_campaign=multisite_migrate_free)** — protect an entire network from Network Admin without upgrading.
* **[Shared hosting friendly](https://multisitemigrate.rosenheinrich.com/features/shared-hosting/?utm_source=wprepo&utm_medium=link&utm_content=shared_hosting&utm_campaign=multisite_migrate_free)** — chunked HTTP / WP-Cron workers resume safely under tight PHP time limits.
* **[Local WordPress migration](https://multisitemigrate.rosenheinrich.com/docs-backup/?utm_source=wprepo&utm_medium=link&utm_content=docs_backup&utm_campaign=multisite_migrate_free)** — export a portable archive and import it on another install with [search & replace](https://multisitemigrate.rosenheinrich.com/docs-restore/?utm_source=wprepo&utm_medium=link&utm_content=docs_restore&utm_campaign=multisite_migrate_free).
* **[One-click WordPress restore](https://multisitemigrate.rosenheinrich.com/docs-restore/?utm_source=wprepo&utm_medium=link&utm_content=docs_restore&utm_campaign=multisite_migrate_free)** — restore any backup created with the plugin from the dashboard.
* **Honest Free vs Pro** — Free is a complete manual backup/migrate tool; Pro adds automation and advanced destinations.

= AI Backup Tools & MCP =

Connect Claude, Cursor, ChatGPT, and similar AI clients to your WordPress site through the WordPress Abilities API and an MCP adapter. AI/MCP needs WordPress 6.9+ (Abilities API); backup and restore work from WordPress 6.2. Free AI abilities need no account or license key:

* **Read** — backup status, archives list, health (with a recommended next action), and last error
* **Write** — start a backup now only after an explicit confirm step

Pro adds extended AI abilities (automation and advanced destinations). Free AI tools are never locked behind a license. See the [MCP connect guide](https://multisitemigrate.rosenheinrich.com/docs/?utm_source=wprepo&utm_medium=link&utm_content=mcp_connect&utm_campaign=multisite_migrate_free).

= Multisite Network Backup (Free) =

Full multisite network backup from Network Admin is free. Subsite admins can back up and restore their own site. You do not need Pro just to protect a network. See [multisite network backups](https://multisitemigrate.rosenheinrich.com/features/multisite-networks/?utm_source=wprepo&utm_medium=link&utm_content=multisite_networks&utm_campaign=multisite_migrate_free).

= Free features (all included) =

* **[Full multisite network backups](https://multisitemigrate.rosenheinrich.com/features/multisite-networks/?utm_source=wprepo&utm_medium=link&utm_content=multisite_networks&utm_campaign=multisite_migrate_free)** — back up and restore an entire network from Network Admin.
* **[WordPress files & database backup](https://multisitemigrate.rosenheinrich.com/features/shared-hosting/?utm_source=wprepo&utm_medium=link&utm_content=shared_hosting&utm_campaign=multisite_migrate_free)** — reliable, chunked jobs over HTTP or WP-Cron that resume on large sites and shared hosts.
* **[One-click restore](https://multisitemigrate.rosenheinrich.com/docs-restore/?utm_source=wprepo&utm_medium=link&utm_content=docs_restore&utm_campaign=multisite_migrate_free)** — restore any backup created with the plugin.
* **[Local export & import](https://multisitemigrate.rosenheinrich.com/docs-backup/?utm_source=wprepo&utm_medium=link&utm_content=docs_backup&utm_campaign=multisite_migrate_free)** — move a site between installs with a portable archive.
* **[Search & replace](https://multisitemigrate.rosenheinrich.com/docs-restore/?utm_source=wprepo&utm_medium=link&utm_content=docs_restore&utm_campaign=multisite_migrate_free)** — rewrite URLs and paths during import and migration, with serialized-data support.
* **[AES-256 archive encryption](https://multisitemigrate.rosenheinrich.com/docs-backup/?utm_source=wprepo&utm_medium=link&utm_content=docs_backup&utm_campaign=multisite_migrate_free)** — optionally encrypt backup archives.
* **One local scheduled backup** — daily, weekly, or monthly to local storage (one schedule per admin context).
* **Email notifications** — alerts for manual backups, scheduled backups, restores, and imports.
* **[Health dashboard](https://multisitemigrate.rosenheinrich.com/docs/?utm_source=wprepo&utm_medium=link&utm_content=docs&utm_campaign=multisite_migrate_free)** — environment checks and backup status at a glance.
* **[AI Backup Tools & MCP](https://multisitemigrate.rosenheinrich.com/docs/?utm_source=wprepo&utm_medium=link&utm_content=mcp_connect&utm_campaign=multisite_migrate_free)** — connect Claude, Cursor, or ChatGPT to check status, archives, and health; start a backup with confirm (free, no key).
* **[Subsite admin backup & restore](https://multisitemigrate.rosenheinrich.com/features/multisite-networks/?utm_source=wprepo&utm_medium=link&utm_content=multisite_networks&utm_campaign=multisite_migrate_free)** — on multisite, site administrators with manage_options can back up and restore their own subsite from the site dashboard (included free; no seat delegation UI).
* Accessibility-oriented admin UI (WCAG 2.2 AA target)
* Mobile-responsive interface

= Secure WordPress Backups =

Create full WordPress backups of files and database with optional [AES-256 archive encryption](https://multisitemigrate.rosenheinrich.com/docs-backup/?utm_source=wprepo&utm_medium=link&utm_content=docs_backup&utm_campaign=multisite_migrate_free). Free backups are stored locally on your server. [Cloud WordPress backups](https://multisitemigrate.rosenheinrich.com/features/cloud-backup/?utm_source=wprepo&utm_medium=link&utm_content=cloud_backup&utm_campaign=multisite_migrate_free) (Google Drive, Dropbox, Amazon S3, SFTP) are available in [Multisite Migrate Pro](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=get_pro&utm_campaign=multisite_migrate_free).

= One-Click WordPress Restore =

[Restore WordPress backups](https://multisitemigrate.rosenheinrich.com/docs-restore/?utm_source=wprepo&utm_medium=link&utm_content=docs_restore&utm_campaign=multisite_migrate_free) from the Multisite Migrate → Backups screen. Choose a completed archive and restore without leaving the dashboard — a straightforward path back when something goes wrong.

= Fast WordPress Migrations =

[Migrate WordPress sites](https://multisitemigrate.rosenheinrich.com/docs-backup/?utm_source=wprepo&utm_medium=link&utm_content=docs_backup&utm_campaign=multisite_migrate_free) between domains or hosts with local export and import. Use [search & replace](https://multisitemigrate.rosenheinrich.com/docs-restore/?utm_source=wprepo&utm_medium=link&utm_content=docs_restore&utm_campaign=multisite_migrate_free) to rewrite URLs and paths, including serialized data, so the destination install matches the new location.

= WordPress Backup Plugin for Shared Hosting =

[Chunked, resumable backup jobs](https://multisitemigrate.rosenheinrich.com/features/shared-hosting/?utm_source=wprepo&utm_medium=link&utm_content=shared_hosting&utm_campaign=multisite_migrate_free) are designed for shared hosting and managed hosts with short PHP execution times. Work continues over HTTP or WP-Cron instead of one long request that hits a gateway timeout.

= Partial & Scoped WordPress Backups =

Scope backups to what you need: full network, filtered network, a single subsite, or profile-oriented runs (for example media, plugins, or themes where supported). That helps keep archives smaller and restores faster on large multisite networks. See the [backup guide](https://multisitemigrate.rosenheinrich.com/docs-backup/?utm_source=wprepo&utm_medium=link&utm_content=docs_backup&utm_campaign=multisite_migrate_free).

= Clone WordPress / Staging Workflows =

Use Free local export/import to clone a site to another WordPress install for staging or sandbox work. [Staging push](https://multisitemigrate.rosenheinrich.com/features/staging/?utm_source=wprepo&utm_medium=link&utm_content=staging&utm_campaign=multisite_migrate_free) from the dashboard is a [Pro](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=pricing&utm_campaign=multisite_migrate_free) workflow. Empty destinations without WordPress pre-installed use the [empty-server installer](https://multisitemigrate.rosenheinrich.com/features/empty-server-migration/?utm_source=wprepo&utm_medium=link&utm_content=empty_server_migration&utm_campaign=multisite_migrate_free) in Pro.

= Drag & Drop / Empty-Server WordPress Installer (Pro) =

[Empty-server drag & drop migration](https://multisitemigrate.rosenheinrich.com/features/empty-server-migration/?utm_source=wprepo&utm_medium=link&utm_content=empty_server_migration&utm_campaign=multisite_migrate_free) packages a guided installer for blank servers (PHP + database + write access — no WordPress pre-install required). This is part of Multisite Migrate Pro, not the free plugin.

= Server-to-Server WordPress Import (Pro) =

[Remote server-to-server import](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=pricing&utm_campaign=multisite_migrate_free) pulls a package between servers without a slow browser upload of multi-GB archives. Available in Pro alongside [cloud storage](https://multisitemigrate.rosenheinrich.com/features/cloud-backup/?utm_source=wprepo&utm_medium=link&utm_content=cloud_backup&utm_campaign=multisite_migrate_free).

= Peer transfer (Pro) =

[Peer transfer](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=peer_transfer&utm_campaign=multisite_migrate_free) pushes or pulls a site between two WordPress installs that both run Multisite Migrate Pro. Choose tables and files, run search & replace, and follow live progress — without downloading the archive through the browser. This is part of Multisite Migrate Pro, not the free plugin.

= Scheduled WordPress Backups =

Free includes **one local scheduled backup** (daily, weekly, or monthly) via WP-Cron, stored on your server. [Plus/Pro](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=pricing&utm_campaign=multisite_migrate_free) adds multiple schedules, cloud destinations, incremental file backups.

= Cloud Storage for WordPress Backups (Pro) =

Send archives to [Google Drive, Dropbox, Amazon S3, or SFTP](https://multisitemigrate.rosenheinrich.com/features/cloud-backup/?utm_source=wprepo&utm_medium=link&utm_content=cloud_backup&utm_campaign=multisite_migrate_free). Local Free storage remains available; cloud destinations unlock with [Multisite Migrate Pro](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=get_pro&utm_campaign=multisite_migrate_free).

= WP-CLI WordPress Backups (Pro) =

Automate backup and related workflows with [WP-CLI commands](https://multisitemigrate.rosenheinrich.com/docs-wp-cli/?utm_source=wprepo&utm_medium=link&utm_content=docs_wp_cli&utm_campaign=multisite_migrate_free) in Multisite Migrate Pro — useful for ops teams and scripted environments.

= Split Multisite Network into Standalone Sites (Pro) =

Need each Multisite site as its own WordPress install? [Multisite Migrate Pro](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=get_pro&utm_campaign=multisite_migrate_free) can create one archive per site, or extract one site from an existing network backup — then restore once per destination. Step-by-step marketing docs: [network split guide](https://multisitemigrate.rosenheinrich.com/docs-network-split/?utm_source=wprepo&utm_medium=link&utm_content=docs_network_split&utm_campaign=multisite_migrate_free). This workflow is part of Multisite Migrate Pro, not the free plugin.

= Multisite Migrate Pro =

Advanced workflows ship as a separate plugin, **Multisite Migrate Pro**, from our website. Pro is **not required** for any Free feature above. Pro adds:

* **[Cloud, multiple, and incremental schedules](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=pricing&utm_campaign=multisite_migrate_free)** — beyond Free’s one local schedule
* **[Per-site access controls and subsite seat limits](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=pricing&utm_campaign=multisite_migrate_free)** for large networks (Settings → Site access)
* **[Cloud storage](https://multisitemigrate.rosenheinrich.com/features/cloud-backup/?utm_source=wprepo&utm_medium=link&utm_content=cloud_backup&utm_campaign=multisite_migrate_free)** destinations (Google Drive, Dropbox, Amazon S3, SFTP)
* **[Remote (server-to-server) import](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=pricing&utm_campaign=multisite_migrate_free)**
* **[Peer transfer (Push & Pull)](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=peer_transfer&utm_campaign=multisite_migrate_free)** — move a site between two installs without downloading the archive through the browser
* **[Empty-server drag & drop installer](https://multisitemigrate.rosenheinrich.com/features/empty-server-migration/?utm_source=wprepo&utm_medium=link&utm_content=empty_server_migration&utm_campaign=multisite_migrate_free)**
* **[Staging push](https://multisitemigrate.rosenheinrich.com/features/staging/?utm_source=wprepo&utm_medium=link&utm_content=staging&utm_campaign=multisite_migrate_free)**
* **[WP-CLI commands](https://multisitemigrate.rosenheinrich.com/docs-wp-cli/?utm_source=wprepo&utm_medium=link&utm_content=docs_wp_cli&utm_campaign=multisite_migrate_free)**
* **[Network split / extract to standalone](https://multisitemigrate.rosenheinrich.com/docs-network-split/?utm_source=wprepo&utm_medium=link&utm_content=docs_network_split&utm_campaign=multisite_migrate_free)** — one archive per site, or extract one blog from a network backup
* **[Priority support](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=pricing&utm_campaign=multisite_migrate_free)**

Learn more and [compare plans](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=compare_plans&utm_campaign=multisite_migrate_free).

= Translations =

Help translate the Multisite Migrate UI and WordPress.org readme on [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/rosenheinrich-multisite-migrate/) — see the [Polyglots handbook](https://make.wordpress.org/polyglots/handbook/translating/how-to-translate/) to get started.

= Source =

Browse the Free plugin source and open issues on [GitHub](https://github.com/Rosenheinrich-Sofware-Solutions/rosenheinrich-multisite-migrate?utm_source=wprepo&utm_medium=readme&utm_campaign=multisite_migrate_free&utm_content=github_source). Installs and updates remain on WordPress.org.

== External Services ==

The free plugin does not phone home by default. It may contact external services only when you opt in or submit something explicitly.

= Rosenheinrich portal (multisitemigrate.rosenheinrich.com) =

* **Usage telemetry (opt-in, unticked by default):** setup screen or Settings → Privacy. Sends a pseudonymous install ID, site hash (not your URL), plugin/WordPress/PHP versions, setup choices, and sanitized backup/import/restore outcomes (including whether it was the first backup and a coarse time since activation) — never backup files or database content. While the plugin is active, an environment snapshot is sent about once a day. If you opted in, anonymous deactivation and uninstall signals are recorded when the plugin is deactivated or deleted.
* **Product emails (opt-in, unticked by default):** setup screen only. Sends your admin email, name, site URL, site title, and version info to subscribe you to product updates.
* **Feedback (explicit submit):** in-plugin feedback or deactivate survey. Sends your message, rating/reason, versions, and site hash; with the deactivate survey also a short summary of recent plugin errors (no file paths or email addresses); a contact email only if you type one in on deactivate.

Privacy Policy: https://multisitemigrate.rosenheinrich.com/privacy-policy/
Terms (Plus/Pro only; free plugin is GPLv2): https://multisitemigrate.rosenheinrich.com/terms-of-service/

= GitHub (optional) =

If you install the MCP Adapter from AI Agents, the plugin fetches the latest release from api.github.com (WordPress/mcp-adapter). Admin action only.

= Pro only (not used by Free) =

License validation and Google Drive OAuth use the same portal; see the Privacy Policy.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it from Plugins → Add New.
2. Activate it through the Plugins screen. On a multisite network you can network-activate it.
3. Open **Multisite Migrate** in the admin menu and create your first backup.
4. Optional: review the [documentation](https://multisitemigrate.rosenheinrich.com/docs/?utm_source=wprepo&utm_medium=link&utm_content=docs&utm_campaign=multisite_migrate_free) and [shared hosting](https://multisitemigrate.rosenheinrich.com/features/shared-hosting/?utm_source=wprepo&utm_medium=link&utm_content=shared_hosting&utm_campaign=multisite_migrate_free) notes for large sites.

== Frequently Asked Questions ==

= What AI / MCP features are included free? =

Free includes AI abilities for backup status, archives, health (with a recommended next action), last error, and starting a backup with an explicit confirm step. Connect Claude, Cursor, ChatGPT, or similar clients via MCP — no account or license key. Extended AI abilities ship in [Multisite Migrate Pro](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=pricing&utm_campaign=multisite_migrate_free). Connect guide: [docs](https://multisitemigrate.rosenheinrich.com/docs/?utm_source=wprepo&utm_medium=link&utm_content=mcp_connect&utm_campaign=multisite_migrate_free).

= Do I need Claude or ChatGPT to use backups? =

No. Backup, restore, and local migration work entirely from the WordPress admin UI. AI / MCP is optional for operators who want to query status or start a confirmed backup from their AI client.

= How can I help translate Multisite Migrate? =

Translations live on WordPress.org GlotPress — you do not need to edit files in the plugin ZIP.

1. Open https://translate.wordpress.org/projects/wp-plugins/rosenheinrich-multisite-migrate/
2. Choose your language (Stable, Development, and both Readme columns).
3. Suggest translations for untranslated strings.
4. New to translating WordPress? Read https://make.wordpress.org/polyglots/handbook/translating/how-to-translate/

Approved Stable translations (about 90% Current) become an automatic language pack for that locale.

= Does this work on a normal single-site WordPress install? =

Yes. Full backup, restore, local migrate, and search & replace work on single-site installs. Full-network backup from Network Admin is included when you run Multisite — no paid Multisite add-on required.

= Does the free version really support WordPress Multisite? =

Yes. [Backing up and restoring a full multisite network](https://multisitemigrate.rosenheinrich.com/features/multisite-networks/?utm_source=wprepo&utm_medium=link&utm_content=multisite_networks&utm_campaign=multisite_migrate_free) from Network Admin is included in the free plugin, with no key or upgrade required. Site administrators with the manage_options capability can also back up and restore their own subsite from the site admin dashboard. Delegating subsite access with seat limits under Settings → Site access is part of [Multisite Migrate Pro](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=get_pro&utm_campaign=multisite_migrate_free).

= Does Free include scheduled backups? =

Yes. Free includes one local scheduled backup (daily, weekly, or monthly) stored on your server. Multiple schedules, cloud destinations, incremental backups are part of [Multisite Migrate Plus/Pro](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=pricing&utm_campaign=multisite_migrate_free).

= Do I need an account or license key? =

No. The free plugin is completely functional with no key, no trial and no time or usage limits. See [pricing](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=pricing&utm_campaign=multisite_migrate_free) only if you want Pro workflows such as cloud schedules or the empty-server installer.

= Where are WordPress backups stored? =

In the free version, backups are stored locally in your WordPress uploads area. [Cloud storage destinations](https://multisitemigrate.rosenheinrich.com/features/cloud-backup/?utm_source=wprepo&utm_medium=link&utm_content=cloud_backup&utm_campaign=multisite_migrate_free) are part of the separate Multisite Migrate Pro plugin.

= How do I restore a WordPress backup? =

Open Multisite Migrate → Backups, choose a backup and restore it directly from the dashboard. Details: [restore documentation](https://multisitemigrate.rosenheinrich.com/docs-restore/?utm_source=wprepo&utm_medium=link&utm_content=docs_restore&utm_campaign=multisite_migrate_free).

= How do I migrate a WordPress site to a new host? =

Create a backup (or export a portable archive), move it to the destination, then [import and restore](https://multisitemigrate.rosenheinrich.com/docs-restore/?utm_source=wprepo&utm_medium=link&utm_content=docs_restore&utm_campaign=multisite_migrate_free) with search & replace for the new URL. For a blank server without WordPress installed, use the [empty-server installer](https://multisitemigrate.rosenheinrich.com/features/empty-server-migration/?utm_source=wprepo&utm_medium=link&utm_content=empty_server_migration&utm_campaign=multisite_migrate_free) in Pro.

= What is the difference between the free plugin and Pro? =

The free plugin handles manual backups, one local scheduled backup, restore, local import/export, search & replace, encryption, [full multisite network backups](https://multisitemigrate.rosenheinrich.com/features/multisite-networks/?utm_source=wprepo&utm_medium=link&utm_content=multisite_networks&utm_campaign=multisite_migrate_free) from Network Admin, subsite admin backup & restore on the administrator's own subsite, and free AI / MCP tools (status, archives, health, confirmed backup-now). Pro is a separate download that adds cloud / multiple / incremental schedules, [cloud storage](https://multisitemigrate.rosenheinrich.com/features/cloud-backup/?utm_source=wprepo&utm_medium=link&utm_content=cloud_backup&utm_campaign=multisite_migrate_free), site access delegation with subsite seats, remote import, [peer transfer (Push & Pull)](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=peer_transfer&utm_campaign=multisite_migrate_free), the [empty-server installer](https://multisitemigrate.rosenheinrich.com/features/empty-server-migration/?utm_source=wprepo&utm_medium=link&utm_content=empty_server_migration&utm_campaign=multisite_migrate_free), [staging](https://multisitemigrate.rosenheinrich.com/features/staging/?utm_source=wprepo&utm_medium=link&utm_content=staging&utm_campaign=multisite_migrate_free), [WP-CLI](https://multisitemigrate.rosenheinrich.com/docs-wp-cli/?utm_source=wprepo&utm_medium=link&utm_content=docs_wp_cli&utm_campaign=multisite_migrate_free), [network split / extract to standalone](https://multisitemigrate.rosenheinrich.com/docs-network-split/?utm_source=wprepo&utm_medium=link&utm_content=docs_network_split&utm_campaign=multisite_migrate_free), and extended AI abilities. [Compare plans](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=compare_plans&utm_campaign=multisite_migrate_free).

= Can I split a Multisite network into separate single-site installs? =

That workflow is part of [Multisite Migrate Pro](https://multisitemigrate.rosenheinrich.com/pricing/?utm_source=wprepo&utm_medium=link&utm_content=get_pro&utm_campaign=multisite_migrate_free): create one archive per site, or extract one site from a network backup, then restore once per destination. Full walkthrough: [network split documentation](https://multisitemigrate.rosenheinrich.com/docs-network-split/?utm_source=wprepo&utm_medium=link&utm_content=docs_network_split&utm_campaign=multisite_migrate_free). The free plugin covers full-network backup/restore and local import between existing WordPress installs.

= Does this WordPress backup plugin work on shared hosting? =

Yes. Backups use [chunked, resumable workers](https://multisitemigrate.rosenheinrich.com/features/shared-hosting/?utm_source=wprepo&utm_medium=link&utm_content=shared_hosting&utm_campaign=multisite_migrate_free) designed for shared hosts and tight PHP time limits.

= Can I migrate WordPress to an empty server? =

Empty-server drag & drop install (no WordPress pre-installed on the target) is part of [Multisite Migrate Pro](https://multisitemigrate.rosenheinrich.com/features/empty-server-migration/?utm_source=wprepo&utm_medium=link&utm_content=empty_server_migration&utm_campaign=multisite_migrate_free). The free plugin covers local export/import between existing WordPress installs.

= Do I need coding skills? =

No. Backup, restore, and local migration are driven from the WordPress admin UI. Pro adds optional [WP-CLI](https://multisitemigrate.rosenheinrich.com/docs-wp-cli/?utm_source=wprepo&utm_medium=link&utm_content=docs_wp_cli&utm_campaign=multisite_migrate_free) for automation.

= Will Multisite Migrate slow down my site? =

Backup and restore run as background-style chunked jobs. They are designed not to hold a single request open for the entire archive build. See [shared hosting guidance](https://multisitemigrate.rosenheinrich.com/features/shared-hosting/?utm_source=wprepo&utm_medium=link&utm_content=shared_hosting&utm_campaign=multisite_migrate_free).

= How do I report a security issue? =

Report security issues to phillip@rosenheinrich.com; we aim to respond within 72 hours. Please do not post security problems in the public support forum or in public GitHub issues.

== Changelog ==

= 1.2.25 =
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
4. Import & Restore: upload a .zip, .daf, or .enc backup; large files upload in chunks.
5. Schedules: one local Free schedule — interval, time, full profile, and retention.
6. Settings: encrypt archives at rest, safety snapshot before restore, exclusions, retention, and database export mode.
7. Activity Log: filter plugin events by type and date; on-disk logs rotate automatically.
8. Health: hosting checks for disk space, PHP limits, ZipArchive, mysqldump, and the backup engine.
