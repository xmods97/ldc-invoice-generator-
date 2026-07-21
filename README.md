# LDC Invoice Generator

Reusable private WordPress invoice and project-proposal builder.

Author: [Xmods](https://github.com/xmods97)

## MVP features

- live invoice preview based on the supplied LDC invoice layout;
- repeatable scope-of-work and payment sections;
- automatic payment-total validation;
- server-side invoice archive with editing and JSON transfer;
- company identity stored in WordPress settings, not plugin files;
- print dialog optimized for US Letter and PDF saving;
- JSON export for backup and reuse;
- HTML email delivery through `wp_mail()` and the site's existing FluentSMTP plugin;
- private-link access with nonce validation and administrator settings.

## Install

1. Zip the `ldc-invoice-generator` folder.
2. In WordPress, open **Plugins -> Add New Plugin -> Upload Plugin**.
3. Upload the ZIP and activate it.
4. Open **Invoices** in the WordPress admin menu.
5. Open **Invoices -> Company Settings** and enter the company identity, license, contact details, and default tax rate.

For PDF output, use **Print / PDF**, choose **Save as PDF**, and enable background graphics.

Company data and private access keys are stored in the WordPress database and are excluded from release packages.

## Automatic updates

The plugin checks the latest public GitHub Release. Create and push a version tag such as `v0.9.0`; GitHub Actions builds an installable `ldc-invoice-generator.zip` release asset. WordPress then offers newer releases through its normal Plugins update screen.

Automatic installation can be enabled from the protected **Company Settings** page or the WordPress **Invoices -> Company Settings** screen. Release archives retain the stable `ldc-invoice-generator/ldc-invoice-generator.php` path so WordPress keeps the plugin active after updating.
