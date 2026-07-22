# LDC Invoice Generator

Private WordPress invoice and project-proposal builder with saved invoice records, PDF output, email delivery, JSON/Excel export, backups, GitHub-based updates, and encrypted invoice storage.

Author: [xmods97](https://github.com/xmods97)

## What this plugin does

LDC Invoice Generator creates a private invoice workspace inside WordPress. It is designed for contractors and service businesses that need to prepare proposal-style invoices from a protected link without giving the client or office user access to the WordPress admin dashboard.

The plugin can be used from:

- the WordPress admin menu: **Invoices**;
- a private frontend builder page;
- a private frontend invoice archive page;
- a private company settings page.

## Main features

### Invoice builder

- Private invoice/proposal form protected by an access key.
- Live invoice preview matching the supplied invoice layout.
- Company logo pulled from the WordPress site logo.
- Company license, phone, and address from plugin settings.
- Client details: name, company, email, phone, contact person, billing address, city/state/ZIP.
- Project details: project name, type, address, owner/homeowner, estimator/project manager, dates, permit/reference.
- Repeatable scope-of-work sections.
- Optional work-item pricing.
- Optional automatic total calculation from work items.
- Optional US sales tax calculation.
- Manual total mode when the user wants to enter only the final price.
- Repeatable payment schedule rows.
- Payment schedule validation against invoice total.
- Default clean invoice state when opening the private builder link.
- Saved invoices can still be opened from the archive for editing.

### PDF and print

- Print/PDF output optimized for US Letter.
- Browser print support with background colors for the invoice table.
- PDF preview from the invoice archive without opening the print dialog.
- Email PDF generation through `pdfMake`.
- Email attachments are sent as PDF documents.

### Email delivery

- Send invoice by email from the builder.
- Re-send saved invoices from the archive.
- Uses WordPress `wp_mail()`.
- Works with the site's configured mail plugin/SMTP provider, such as FluentSMTP.
- Email body is a clean message-only design; the invoice itself is attached as PDF.
- Basic rate limiting for email sends.

### Invoice archive

- Server-side saved invoice records.
- Invoice list with client, project, address, total, and updated date.
- Edit saved invoice.
- Open saved invoice as PDF preview.
- Re-send saved invoice by email.
- Multi-select invoices.
- Select all invoices.
- Bulk delete selected invoices.
- Export selected invoices to JSON.
- Export all invoices to JSON.
- Export selected invoices to Excel-compatible `.xls`.
- Export all invoices to Excel-compatible `.xls`.
- Import JSON invoice backups.

### Backup and restore

- Manual **Download backup** button in the invoice archive.
- Importable JSON backup format.
- Automatic backup snapshot after save/delete operations.
- Backup snapshots are stored in `wp-content/uploads/ldc-invoice-backups`.
- Backup folder includes protection files when the server supports them.
- Server backup snapshot is encrypted when OpenSSL is available.

### Security

- Private frontend access key.
- WordPress nonce validation for AJAX actions.
- Saved invoice records are encrypted at rest when OpenSSL is available.
- Encryption uses AES-256-GCM.
- Encryption key material is derived from WordPress salts, site URL, and the plugin access key.
- Existing plaintext records are migrated to encrypted storage on first invoice-list access after update.
- Server backup file is also encrypted when OpenSSL is available.
- Company data and private access keys are stored in the WordPress database, not in plugin files.
- Release packages do not include company data, saved invoices, or private keys.
- Built-in **Security check** panel verifies encryption, backup protection, OpenSSL support, access key length, and storage status without exposing client data.

Important note: plugin-level encryption protects invoice data at rest in the database and backup files. A person with full server access and access to `wp-config.php` can still theoretically decrypt records, because WordPress salts live on the same server. This is expected for application-level encryption.

## Security check

Open:

**Invoices -> Company Settings -> Security check -> Run security check**

The check reports:

- invoice database storage status;
- cipher status;
- OpenSSL support;
- saved invoice count;
- whether obvious plaintext invoice fields are visible in raw storage;
- backup file status;
- backup encryption status;
- backup folder protection status;
- private access key length.

## Installation

1. Download the release ZIP: `ldc-invoice-generator.zip`.
2. In WordPress, open **Plugins -> Add New Plugin -> Upload Plugin**.
3. Upload the ZIP and activate it.
4. Open **Invoices** in the WordPress admin menu.
5. Open **Invoices -> Company Settings**.
6. Enter company identity, license, contact details, and default tax rate.
7. Use the private builder link to create invoices.

## Typical workflow

1. Open the private invoice builder link.
2. Fill client and project details.
3. Add scope-of-work sections.
4. Choose manual total or automatic total from work items.
5. Add tax if needed.
6. Add payment schedule if needed.
7. Review live preview.
8. Save invoice.
9. Print/PDF or send by email.
10. Use the archive to edit, preview PDF, export, backup, or re-send.

## Automatic updates

The plugin checks the latest public GitHub Release. When a version tag such as `v0.9.29` is pushed, GitHub Actions builds an installable `ldc-invoice-generator.zip` release asset. WordPress can then offer the update through the normal Plugins update screen.

Automatic installation can be enabled from:

- protected **Company Settings** page;
- WordPress **Invoices -> Company Settings** screen.

Release archives retain the stable `ldc-invoice-generator/ldc-invoice-generator.php` path so WordPress keeps the plugin active after updating.

## Data storage

Stored in WordPress database:

- company settings;
- private access key;
- encrypted invoice records;
- automatic update preference.

Stored in uploads folder:

- encrypted backup snapshot files.

Stored in plugin files:

- plugin code;
- static CSS/JS/assets;
- update integration code.

Not stored in the public repository or release package:

- company private data;
- client invoice data;
- saved invoice records;
- access keys;
- backup snapshots.

## Requirements

- WordPress 6.x or newer recommended.
- PHP 7.4 or newer.
- OpenSSL PHP extension recommended for encrypted storage.
- A configured WordPress mail/SMTP provider for reliable email delivery.

## Development notes

- Main plugin file: `ldc-invoice-generator.php`.
- Builder UI: `assets/admin.js` and `assets/admin.css`.
- Archive UI: `assets/list.js`.
- Settings UI: `assets/settings.js`.
- PDF generation: `assets/vendor/pdfmake.min.js` and `assets/vendor/vfs_fonts.js`.
- GitHub update package name: `ldc-invoice-generator.zip`.
